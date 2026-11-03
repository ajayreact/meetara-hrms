import { Extension, Node } from '@tiptap/core';
import { Highlight } from '@tiptap/extension-highlight';
import { OrderedList } from '@tiptap/extension-list';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { TextStyleKit } from '@tiptap/extension-text-style';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { AlignCenter, AlignJustify, AlignLeft, AlignRight, Bold, Italic, Link2, List, ListOrdered, Redo2, Table as TableIcon, Underline, Undo2 } from 'lucide-react';
import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';

const fonts = ['Times New Roman', 'Arial', 'Calibri', 'Georgia', 'Courier New'];
const sizes = ['10pt', '11pt', '12pt', '14pt', '16pt', '18pt', '22pt'];
const colors = ['#0f172a', '#1d4ed8', '#b91c1c', '#15803d', '#7c3aed'];
const highlights = [
    { label: 'Yellow', value: '#fef08a' },
    { label: 'Green', value: '#bbf7d0' },
    { label: 'Blue', value: '#bfdbfe' },
];

const namedPlaceholders = [
    ['employee_name', 'Employee name'],
    ['employee_id', 'Employee ID'],
    ['department', 'Department'],
    ['designation', 'Designation'],
    ['reporting_manager', 'Reporting manager'],
    ['joining_date', 'Joining date'],
    ['date_of_issue', 'Date of issue'],
    ['work_location', 'Work location'],
    ['work_mode', 'Work mode'],
    ['employment_type', 'Employment type'],
    ['monthly_salary', 'Monthly salary'],
    ['salary_in_words', 'Salary in words'],
    ['annual_ctc', 'Annual CTC'],
    ['annual_ctc_in_words', 'Annual CTC in words'],
    ['basic_salary', 'Basic salary'],
    ['hra', 'HRA'],
    ['special_allowance', 'Special allowance'],
    ['working_hours', 'Working hours'],
    ['probation_period', 'Probation period'],
    ['notice_period', 'Notice period'],
    ['company_legal_name', 'Company legal name'],
    ['company_address', 'Company address'],
    ['candidate_address', 'Candidate address'],
    ['signatory_name', 'Signatory name'],
    ['signatory_designation', 'Signatory designation'],
    ['hr_email', 'HR email'],
] as const;

const LetterList = OrderedList.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            type: {
                default: null,
                parseHTML: (element) => element.getAttribute('type'),
                renderHTML: (attributes) => (attributes.type ? { type: attributes.type } : {}),
            },
        };
    },
});

const BlockSpacing = Extension.create({
    name: 'blockSpacing',
    addGlobalAttributes() {
        return [
            {
                types: ['paragraph', 'heading'],
                attributes: {
                    textAlign: {
                        default: null,
                        parseHTML: (element) => element.style.textAlign || null,
                        renderHTML: (attributes) => {
                            const parts = [
                                attributes.textAlign ? `text-align: ${attributes.textAlign}` : '',
                                attributes.lineHeight ? `line-height: ${attributes.lineHeight}` : '',
                                attributes.spacingBefore ? `margin-top: ${attributes.spacingBefore}` : '',
                                attributes.spacingAfter ? `margin-bottom: ${attributes.spacingAfter}` : '',
                            ].filter(Boolean);

                            return parts.length ? { style: parts.join('; ') } : {};
                        },
                    },
                    lineHeight: {
                        default: null,
                        parseHTML: (element) => element.style.lineHeight || null,
                        renderHTML: () => ({}),
                    },
                    spacingBefore: {
                        default: null,
                        parseHTML: (element) => element.style.marginTop || null,
                        renderHTML: () => ({}),
                    },
                    spacingAfter: {
                        default: null,
                        parseHTML: (element) => element.style.marginBottom || null,
                        renderHTML: () => ({}),
                    },
                },
            },
        ];
    },
});

const PageBreakNode = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,
    parseHTML: () => [{ tag: 'div[data-page-break]' }],
    renderHTML: () => ['div', { 'data-page-break': 'true' }],
    addNodeView() {
        return () => {
            const dom = document.createElement('div');
            dom.className = 'page-break';
            dom.textContent = 'Page break';

            return { dom };
        };
    },
});

const SignatureSlot = Node.create({
    name: 'signatureSlot',
    group: 'block',
    atom: true,
    selectable: true,
    parseHTML: () => [{ tag: 'div[data-signature]' }],
    renderHTML: () => ['div', { 'data-signature': 'hr' }],
    addNodeView() {
        return () => {
            const dom = document.createElement('div');
            dom.className = 'signature-slot';
            dom.textContent = 'HR signature image is placed here. This is not a legal e-signature.';

            return { dom };
        };
    },
});

export function plainToHtml(text: string): string {
    const paragraphs = text.split(/\n{2,}/).map((paragraph) => paragraph.trim()).filter(Boolean);

    if (paragraphs.length === 0) {
        return '<p></p>';
    }

    return paragraphs.map((paragraph) => `<p>${paragraph.split('\n').map(escapeHtml).join('<br>')}</p>`).join('');
}

export interface LetterPreview {
    signatureUrl?: string | null;
    signatureWidth?: number;
    signatureHeight?: number;
    signatureAlign?: string;
    signatureGapBefore?: number;
    signatureGapAfter?: number;
}

export function previewPages(html: string, sample: Record<string, string>, preview?: LetterPreview): string[] {
    const filled = html.replace(/\{\{([a-z0-9_]+)\}\}/g, (match, key: string) => {
        const value = sample[key];

        return value ? escapeHtml(value) : match;
    });
    const withSignature = filled.replace(/<div data-signature="hr">\s*<\/div>/gi, signaturePreview(preview));
    const pages = withSignature.split(/<div data-page-break="true"><\/div>/i).map((page) => page.trim()).filter(Boolean);

    return pages.length > 0 ? pages : ['<p></p>'];
}

export interface LetterPageLayout {
    imageUrl: string | null;
    pageWidth: number;
    pageHeight: number;
    marginTop: number;
    marginRight: number;
    marginBottom: number;
    marginLeft: number;
    headerHeight: number;
    footerHeight: number;
}

export function LetterPage({ html, layout }: { html: string; layout: LetterPageLayout | null }) {
    if (!layout?.imageUrl || !layout.pageWidth || !layout.pageHeight) {
        return <article className="letter-sheet" dangerouslySetInnerHTML={{ __html: html }} />;
    }

    const top = Math.max(layout.marginTop, layout.headerHeight);
    const bottom = Math.max(layout.marginBottom, layout.footerHeight);
    const bands = layout.headerHeight > 0 || layout.footerHeight > 0;

    return (
        <article
            className="letter-sheet"
            style={{
                position: 'relative',
                boxSizing: 'border-box',
                width: `min(100%, ${layout.pageWidth / 72}in)`,
                minHeight: `${layout.pageHeight / 72}in`,
                backgroundColor: 'white',
                paddingTop: `${top / 72}in`,
                paddingRight: `${layout.marginRight / 72}in`,
                paddingBottom: `${bottom / 72}in`,
                paddingLeft: `${layout.marginLeft / 72}in`,
                ...(bands ? {} : {
                    backgroundImage: `url(${layout.imageUrl})`,
                    backgroundRepeat: 'no-repeat',
                    backgroundPosition: 'center top',
                    backgroundSize: '100% 100%',
                }),
            }}
        >
            {bands && layout.headerHeight > 0 && <div aria-hidden="true" style={bandStyle(layout, 'header')} />}
            <div style={{ position: 'relative', zIndex: 1 }} dangerouslySetInnerHTML={{ __html: html }} />
            {bands && layout.footerHeight > 0 && <div aria-hidden="true" style={bandStyle(layout, 'footer')} />}
        </article>
    );
}

function bandStyle(layout: LetterPageLayout, edge: 'header' | 'footer'): CSSProperties {
    const height = edge === 'header' ? layout.headerHeight : layout.footerHeight;

    return {
        position: 'absolute',
        left: 0,
        right: 0,
        top: edge === 'header' ? 0 : undefined,
        bottom: edge === 'footer' ? 0 : undefined,
        height: `${height / 72}in`,
        overflow: 'hidden',
        pointerEvents: 'none',
        backgroundImage: `url(${layout.imageUrl})`,
        backgroundRepeat: 'no-repeat',
        backgroundSize: '100% auto',
        backgroundPosition: edge === 'header' ? 'top center' : 'bottom center',
    };
}

export function signatureSummary(legal: {
    signatureImageWidth?: number | null;
    signatureImageHeight?: number | null;
    signatureWidth?: number;
    signatureHeight?: number;
    signatureEnabled?: boolean;
}): string {
    const width = legal.signatureImageWidth ?? 0;
    const height = legal.signatureImageHeight ?? 0;

    if (width < 1 || height < 1) {
        return 'The signature file is saved, but its pixel size could not be read.';
    }

    const ratio = (width / height).toFixed(2);
    const boxWidth = legal.signatureWidth ?? 140;
    const boxHeight = legal.signatureHeight ?? 48;
    const boxRatio = boxHeight > 0 ? (boxWidth / boxHeight).toFixed(2) : '0';
    const placement = legal.signatureEnabled
        ? 'Automatic placement is on. The image appears in the closing only, at its own ratio. The employee acceptance line stays blank.'
        : 'Automatic placement is off until an authorized user turns it on. The employee acceptance line stays blank.';

    return `Signature image ${width} × ${height} pixels, aspect ratio ${ratio} to 1. The placement box is ${boxWidth} × ${boxHeight} pt, ratio ${boxRatio} to 1. The image is not stretched to that box. ${placement}`;
}

function signaturePreview(preview?: LetterPreview): string {
    if (!preview?.signatureUrl) {
        return '<div data-signature="hr"></div>';
    }

    const align = preview.signatureAlign === 'center' || preview.signatureAlign === 'right' ? preview.signatureAlign : 'left';
    const width = Math.min(240, Math.max(40, preview.signatureWidth ?? 140));
    const height = Math.min(120, Math.max(16, preview.signatureHeight ?? 48));
    const before = Math.min(40, Math.max(0, preview.signatureGapBefore ?? 8));
    const after = Math.min(40, Math.max(0, preview.signatureGapAfter ?? 6));

    return `<div data-signature="hr" style="text-align:${align};margin-top:${before}pt;margin-bottom:${after}pt"><img src="${escapeAttr(preview.signatureUrl)}" alt="HR signature" style="max-width:${width}px;max-height:${height}px;width:auto;height:auto;object-fit:contain" /></div>`;
}

function escapeHtml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeAttr(value: string): string {
    return escapeHtml(value).replace(/"/g, '&quot;');
}

export function RichLetterEditor({
    body,
    placeholders,
    onChange,
    presentation = 'embedded',
}: {
    body: string;
    placeholders: string[];
    onChange: (body: string) => void;
    presentation?: 'embedded' | 'page';
}) {
    const [linkValue, setLinkValue] = useState('https://');
    const [linkOpen, setLinkOpen] = useState(false);
    const editor = useEditor({
        immediatelyRender: false,
        extensions: [
            StarterKit.configure({
                code: false,
                codeBlock: false,
                strike: false,
                horizontalRule: false,
                blockquote: false,
                orderedList: false,
                link: { openOnClick: false, autolink: false, protocols: ['http', 'https', 'mailto'] },
            }),
            LetterList,
            TextStyleKit.configure({ backgroundColor: false, lineHeight: false }),
            Highlight.configure({ multicolor: true }),
            BlockSpacing,
            PageBreakNode,
            SignatureSlot,
            Table.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
        ],
        content: body,
        editorProps: { attributes: { class: 'letter-surface' } },
        onUpdate: ({ editor: current }) => onChange(current.getHTML()),
    });

    useEffect(() => {
        if (!editor) {
            return;
        }

        if (editor.getHTML() !== body) {
            editor.commands.setContent(body, { emitUpdate: false });
        }
    }, [body, editor]);

    const known = new Set<string>(namedPlaceholders.map(([token]) => token));
    const extras = placeholders.filter((token) => !known.has(token));

    function blockName(): 'paragraph' | 'heading' {
        return editor?.isActive('heading') ? 'heading' : 'paragraph';
    }

    function applyLink() {
        if (!editor) {
            return;
        }

        const href = linkValue.trim();

        if (href === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            setLinkOpen(false);

            return;
        }

        if (!/^(https?:\/\/|mailto:)/i.test(href)) {
            return;
        }

        editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
        setLinkOpen(false);
    }

    return (
        <div className="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_16rem]">
            <style>{`
                .letter-editor .letter-surface { min-height: 32rem; padding: 1.25rem 1.5rem; outline: none; }
                .letter-editor.letter-workspace { background: #f1f5f9; padding: 1rem; }
                .letter-editor.letter-workspace .letter-surface { width: min(100%, 8.5in); min-height: 11in; margin: 0 auto; background: white; box-shadow: 0 1px 2px rgb(15 23 42 / 0.08); }
                .letter-editor p { margin: 0.35em 0; }
                .letter-editor h1 { margin: 0.2em 0 0.6em; font-family: "Times New Roman", Times, serif; font-size: 16pt; font-weight: 700; text-align: center; }
                .letter-editor h2 { margin: 0.8em 0 0.25em; font-size: 12pt; font-weight: 700; }
                .letter-editor h3 { margin: 0.6em 0 0.2em; font-size: 11pt; font-weight: 700; }
                .letter-editor ul { list-style: disc; padding-left: 1.25rem; }
                .letter-editor ol { list-style: decimal; padding-left: 1.25rem; }
                .letter-editor table { width: 100%; border-collapse: collapse; margin: 0.5em 0; }
                .letter-editor td, .letter-editor th { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: top; }
                .letter-editor a { color: #1d4ed8; text-decoration: underline; }
                .letter-editor .page-break { margin: 12px 0; border-top: 1px dashed #94a3b8; color: #64748b; font-size: 12px; text-align: center; }
                .letter-editor .signature-slot { margin: 8px 0; border: 1px dashed #94a3b8; padding: 8px; color: #64748b; font-size: 12px; }
                .letter-sheet { width: min(100%, 8.5in); min-height: 11in; margin: 0 auto 1rem; background: white; padding: 0.85in 0.75in; box-shadow: 0 1px 3px rgb(15 23 42 / 0.12); }
                .letter-sheet p { margin: 0.35em 0; }
                .letter-sheet h1, .letter-sheet h2, .letter-sheet h3 { font-weight: 700; }
                .letter-sheet h1 { text-align: center; font-size: 16pt; }
                .letter-sheet ul { list-style: disc; padding-left: 1.25rem; }
                .letter-sheet ol { list-style: decimal; padding-left: 1.25rem; }
                .letter-sheet table { width: 100%; border-collapse: collapse; }
                .letter-sheet td, .letter-sheet th { border: 1px solid #cbd5e1; padding: 4px 6px; }
            `}</style>
            <div className={`letter-editor overflow-hidden rounded-xl border border-slate-200 bg-white ${presentation === 'page' ? 'letter-workspace' : ''}`}>
                <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 p-2">
                    <select className="rounded-lg border border-slate-200 px-2 py-1 text-sm" aria-label="Paragraph style" defaultValue="paragraph" onChange={(event) => {
                        const value = event.target.value;
                        if (!editor) return;
                        const chain = editor.chain().focus();
                        if (value === 'title') chain.setHeading({ level: 1 }).run();
                        else if (value === 'clause') chain.setHeading({ level: 2 }).run();
                        else if (value === 'subclause') chain.setHeading({ level: 3 }).run();
                        else chain.setParagraph().run();
                    }}>
                        <option value="paragraph">Paragraph</option>
                        <option value="title">Title</option>
                        <option value="clause">Clause</option>
                        <option value="subclause">Subclause</option>
                    </select>
                    <select className="rounded-lg border border-slate-200 px-2 py-1 text-sm" aria-label="Font family" defaultValue="" onChange={(event) => editor?.chain().focus().setFontFamily(event.target.value).run()}>
                        <option value="" disabled>Font</option>
                        {fonts.map((font) => <option key={font} value={font}>{font}</option>)}
                    </select>
                    <select className="rounded-lg border border-slate-200 px-2 py-1 text-sm" aria-label="Font size" defaultValue="" onChange={(event) => editor?.chain().focus().setFontSize(event.target.value).run()}>
                        <option value="" disabled>Size</option>
                        {sizes.map((size) => <option key={size} value={size}>{size}</option>)}
                    </select>
                    <ToolButton label="Bold" active={editor?.isActive('bold')} onClick={() => editor?.chain().focus().toggleBold().run()}><Bold className="size-4" /></ToolButton>
                    <ToolButton label="Italic" active={editor?.isActive('italic')} onClick={() => editor?.chain().focus().toggleItalic().run()}><Italic className="size-4" /></ToolButton>
                    <ToolButton label="Underline" active={editor?.isActive('underline')} onClick={() => editor?.chain().focus().toggleUnderline().run()}><Underline className="size-4" /></ToolButton>
                    {colors.map((color) => (
                        <button key={color} type="button" title="Text color" aria-label={`Text color ${color}`} className="size-6 rounded-full border border-slate-200" style={{ background: color }} onClick={() => editor?.chain().focus().setColor(color).run()} />
                    ))}
                    {highlights.map((color) => (
                        <button key={color.value} type="button" title={`${color.label} highlight`} aria-label={`${color.label} highlight`} className="rounded-md border border-slate-200 px-1.5 py-1 text-xs" style={{ background: color.value }} onClick={() => editor?.chain().focus().toggleHighlight({ color: color.value }).run()}>{color.label}</button>
                    ))}
                    <ToolButton label="Align left" active={editor?.isActive({ textAlign: 'left' })} onClick={() => editor?.chain().focus().updateAttributes(blockName(), { textAlign: 'left' }).run()}><AlignLeft className="size-4" /></ToolButton>
                    <ToolButton label="Align center" active={editor?.isActive({ textAlign: 'center' })} onClick={() => editor?.chain().focus().updateAttributes(blockName(), { textAlign: 'center' }).run()}><AlignCenter className="size-4" /></ToolButton>
                    <ToolButton label="Align right" active={editor?.isActive({ textAlign: 'right' })} onClick={() => editor?.chain().focus().updateAttributes(blockName(), { textAlign: 'right' }).run()}><AlignRight className="size-4" /></ToolButton>
                    <ToolButton label="Justify" active={editor?.isActive({ textAlign: 'justify' })} onClick={() => editor?.chain().focus().updateAttributes(blockName(), { textAlign: 'justify' }).run()}><AlignJustify className="size-4" /></ToolButton>
                    <select className="rounded-lg border border-slate-200 px-2 py-1 text-sm" aria-label="Line spacing" defaultValue="" onChange={(event) => editor?.chain().focus().updateAttributes(blockName(), { lineHeight: event.target.value }).run()}>
                        <option value="" disabled>Line spacing</option>
                        <option value="1">1</option>
                        <option value="1.15">1.15</option>
                        <option value="1.5">1.5</option>
                        <option value="2">2</option>
                    </select>
                    <select className="rounded-lg border border-slate-200 px-2 py-1 text-sm" aria-label="Paragraph spacing" defaultValue="" onChange={(event) => editor?.chain().focus().updateAttributes(blockName(), { spacingAfter: event.target.value }).run()}>
                        <option value="" disabled>Paragraph spacing</option>
                        <option value="0pt">0 pt</option>
                        <option value="4pt">4 pt</option>
                        <option value="8pt">8 pt</option>
                        <option value="12pt">12 pt</option>
                        <option value="16pt">16 pt</option>
                    </select>
                    <ToolButton label="Bullet list" active={editor?.isActive('bulletList')} onClick={() => editor?.chain().focus().toggleBulletList().run()}><List className="size-4" /></ToolButton>
                    <ToolButton label="Numbered list" active={editor?.isActive('orderedList')} onClick={() => editor?.chain().focus().toggleOrderedList().run()}><ListOrdered className="size-4" /></ToolButton>
                    <ToolButton label="Link" active={editor?.isActive('link')} onClick={() => { setLinkValue(String(editor?.getAttributes('link').href ?? 'https://')); setLinkOpen((open) => !open); }}><Link2 className="size-4" /></ToolButton>
                    <ToolButton label="Table" onClick={() => editor?.chain().focus().insertTable({ rows: 2, cols: 2, withHeaderRow: true }).run()}><TableIcon className="size-4" /></ToolButton>
                    <ToolButton label="Page break" onClick={() => editor?.chain().focus().insertContent({ type: 'pageBreak' }).run()}>Break</ToolButton>
                    <ToolButton label="Undo" onClick={() => editor?.chain().focus().undo().run()}><Undo2 className="size-4" /></ToolButton>
                    <ToolButton label="Redo" onClick={() => editor?.chain().focus().redo().run()}><Redo2 className="size-4" /></ToolButton>
                </div>
                {linkOpen && (
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-2 py-2">
                        <label className="text-sm">Link
                            <input className="ml-2 rounded-lg border border-slate-200 px-2 py-1" value={linkValue} onChange={(event) => setLinkValue(event.target.value)} placeholder="https:// or mailto:" />
                        </label>
                        <button type="button" className="rounded-lg bg-blue-700 px-2 py-1 text-sm text-white" onClick={applyLink}>Apply link</button>
                        <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 text-sm" onClick={() => { editor?.chain().focus().unsetLink().run(); setLinkOpen(false); }}>Remove</button>
                    </div>
                )}
                <EditorContent editor={editor} />
            </div>
            <aside className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p className="text-sm font-medium">Placeholders</p>
                <p className="mt-1 text-xs text-slate-500">Inserts at the cursor. Salary words are filled from the offer amounts.</p>
                <div className="mt-2 grid max-h-[36rem] gap-1 overflow-auto">
                    {namedPlaceholders.map(([token, label]) => (
                        <button key={token} type="button" className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-left text-xs" onClick={() => editor?.chain().focus().insertContent(`{{${token}}}`).run()}>
                            {label}
                            <span className="mt-0.5 block text-slate-500">{`{{${token}}}`}</span>
                        </button>
                    ))}
                    {extras.map((token) => (
                        <button key={token} type="button" className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-left text-xs" onClick={() => editor?.chain().focus().insertContent(`{{${token}}}`).run()}>
                            {token}
                        </button>
                    ))}
                </div>
            </aside>
        </div>
    );
}

function ToolButton({ label, active, onClick, children }: { label: string; active?: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" title={label} aria-label={label} aria-pressed={active ?? false} className={`rounded-lg border px-2 py-1 text-sm ${active ? 'border-blue-700 bg-blue-50 text-blue-800' : 'border-slate-200 bg-white'}`} onClick={onClick}>
            {children}
        </button>
    );
}
