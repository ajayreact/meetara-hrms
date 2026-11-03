import { FormEvent, ReactNode } from 'react';
import { SignaturePad } from './signature-pad';

export interface CertificationRecord {
    version: number;
    printedName: string;
    certifiedOn: string;
    certifiedAt: string;
    timezone: string;
    acknowledged: boolean;
    linkId: number;
}

export interface EmployerInfo {
    legalName: string;
    street: string;
    suite: string;
    website: string;
    city: string;
    state: string;
    zip: string;
    ein: string;
    employeeCount: string;
    naics: string;
    optHours: string;
    salary: string;
    salaryFrequency: string;
    otherCompensation: string;
    startDate: string;
}

export interface EmployerCertification {
    signed: boolean;
    printedName: string;
    title: string;
    certifiedOn: string;
}

export interface EvaluationView {
    kind: string;
    from: string;
    to: string;
    narrative: string;
    studentSignedOn: string;
    employerSignedOn: string;
    studentSigned: boolean;
    employerSigned: boolean;
}

export interface GoalRevision {
    editor: string;
    actor: string;
    previous: string;
    next: string;
    at: string;
}

export interface StudentDraft {
    surname: string;
    given_name: string;
    middle_name: string;
    email: string;
    school_recommending: string;
    school_degree: string;
    sevis_school_code: string;
    dso_name: string;
    dso_email: string;
    dso_phone: string;
    sevis_id: string;
    opt_start: string;
    opt_end: string;
    major_cip: string;
    degree_level: string;
    degree_awarded_on: string;
    prior_degree: string;
    ead: string;
    employer_name: string;
    site_name: string;
    site_address: string;
    official_name: string;
    official_title: string;
    official_email: string;
    official_phone: string;
    student_role: string;
    goals: string;
    oversight: string;
    measures: string;
    remarks: string;
    fax: string;
    submit: boolean;
    confirm_certification: boolean;
    confirm_name: boolean;
    signature: File | null;
}

export interface StudentInfo {
    surname: string;
    givenName: string;
    middleName: string;
    email: string;
    schoolRecommending: string;
    schoolDegree: string;
    sevisSchoolCode: string;
    dsoName: string;
    dsoEmail: string;
    dsoPhone: string;
    sevisId: string;
    optStart: string;
    optEnd: string;
    majorCip: string;
    degreeLevel: string;
    degreeAwardedOn: string;
    priorDegree: string;
    ead: string;
}

export interface TrainingInfo {
    studentName: string;
    employerName: string;
    siteName: string;
    siteAddress: string;
    officialName: string;
    officialTitle: string;
    officialEmail: string;
    officialPhone: string;
    studentRole: string;
    goals: string;
    oversight: string;
    measures: string;
    remarks: string;
}

export interface StudentFormView {
    reference: string;
    status: string;
    expiresAt: string;
    locked: boolean;
    finalizedAt: string;
    correctionReason?: string;
    student: StudentInfo;
    training: TrainingInfo;
    goalHistory?: GoalRevision[];
    statements: string[];
    timezone: string;
    localDate: string;
    certification: { latest: CertificationRecord | null; history: CertificationRecord[] };
    employer?: Partial<EmployerInfo> | EmployerInfo[];
    employerCertification?: EmployerCertification;
    evaluations?: EvaluationView[];
}

const line =
    'w-full min-h-11 border-b border-neutral-700 bg-transparent px-0 py-1 text-base text-neutral-950 outline-none placeholder:text-neutral-400 disabled:text-neutral-800 sm:min-h-9 sm:text-sm';
const area = `${line} min-h-28 resize-y`;

const employerCertificationPoints = [
    'I have reviewed and understand this Plan, and I will ensure that the supervising Official follows this Plan.',
    'I will notify the DSO at the earliest available opportunity regarding any material changes to this Plan, including any change of Employer Identification Number resulting from a corporate restructuring, any reduction in compensation from the amount previously submitted on the Plan that is not tied to a reduction in hours worked, any significant decrease in hours per week that a student engages in a STEM training opportunity, and any decrease in hours below the 20-hours-per-week minimum required under this rule.',
    'Within five business days of the termination or departure of the student during the authorized period of OPT, I will report such termination or departure to the DSO. Five business days do not include federal holidays or weekend days, and an employer shall consider a student to have departed when the employer knows the student has left the practical training opportunity, or when the student has not reported for practical training for a period of five consecutive business days without the consent of the employer.',
    'I will adhere to all applicable regulatory provisions that govern this program (see 8 CFR Part 214), including the STEM degree relationship, on-site supervision, sufficient resources, commensurate terms and conditions, and applicable Federal and State employment requirements.',
];

const sectionSixPoints = [
    'I have reviewed, understand, and will follow this Training Plan for STEM OPT Students (Plan).',
    'I will conduct the required periodic evaluations of the student.',
    'I will adhere to all applicable regulatory provisions that govern this program (see 8 CFR Part 214.2(f)(10)(ii)).',
    'I will notify the DSO regarding any material changes to or material deviations from this Plan at the earliest available opportunity, including if I believe the student is not receiving appropriate training as delineated in this Plan.',
];

function employerOf(form: StudentFormView): EmployerInfo {
    const source = form.employer && !Array.isArray(form.employer) ? form.employer : {};

    return {
        legalName: source.legalName ?? '',
        street: source.street ?? '',
        suite: source.suite ?? '',
        website: source.website ?? '',
        city: source.city ?? '',
        state: source.state ?? '',
        zip: source.zip ?? '',
        ein: source.ein ?? '',
        employeeCount: source.employeeCount ?? '',
        naics: source.naics ?? '',
        optHours: source.optHours ?? '',
        salary: source.salary ?? '',
        salaryFrequency: source.salaryFrequency ?? '',
        otherCompensation: source.otherCompensation ?? '',
        startDate: source.startDate ?? '',
    };
}

function textOf(value: string): string {
    return value.trim() === '' ? '—' : value;
}

function displayDate(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return match ? `${match[2]}-${match[3]}-${match[1]}` : textOf(value);
}

function salaryText(employer: EmployerInfo): string {
    if (employer.salary.trim() === '') {
        return '—';
    }
    const frequencies: Record<string, string> = { year: 'per year', month: 'per month', hour: 'per hour' };
    const frequency = frequencies[employer.salaryFrequency] ?? employer.salaryFrequency;

    return frequency === '' ? employer.salary : `${employer.salary} ${frequency}`;
}

function Cell({
    label,
    hint,
    error,
    children,
    className = '',
}: {
    label: string;
    hint?: string;
    error?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`min-w-0 border-b border-neutral-800 p-2 sm:p-3 ${className}`}>
            <p className="text-[11px] leading-snug font-semibold text-neutral-800 sm:text-xs">{label}</p>
            {hint && <p className="mt-0.5 text-[10px] font-medium tracking-wide text-neutral-500 uppercase">{hint}</p>}
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-xs text-rose-700">{error}</p>}
        </div>
    );
}

function Read({ value }: { value: string }) {
    return (
        <p className="min-h-11 border-b border-neutral-300 py-1 text-base break-all whitespace-pre-wrap text-neutral-900 sm:min-h-9 sm:text-sm">
            {textOf(value)}
        </p>
    );
}

function SectionBar({ children }: { children: string }) {
    return (
        <h2 className="border-b border-neutral-800 bg-neutral-200 px-3 py-2 text-center text-xs font-bold tracking-wide text-neutral-950 uppercase sm:text-sm">
            {children}
        </h2>
    );
}

function Plain({ children }: { children: ReactNode }) {
    return <div className="border-b border-neutral-800 px-3 py-3 text-sm leading-6 break-words text-neutral-800">{children}</div>;
}

export function pageForError(key: string): number {
    if (
        [
            'student_role',
            'goals',
            'site_name',
            'site_address',
            'official_name',
            'official_title',
            'official_email',
            'official_phone',
            'employer_name',
        ].includes(key)
    ) {
        return 3;
    }
    if (['confirm_certification', 'confirm_name', 'signature'].includes(key)) {
        return 2;
    }

    return 1;
}

export function StudentDocument({
    current,
    onPage,
    data,
    setData,
    form,
    finalized,
    error,
    printedName,
    reviewing,
    onReview,
    onCancelReview,
    onConfirm,
    processing,
    progress,
}: {
    current: number;
    onPage: (page: number) => void;
    data: StudentDraft;
    setData: (key: keyof StudentDraft, value: string | boolean | File | null) => void;
    form: StudentFormView;
    finalized: boolean;
    error: (key: string) => string;
    printedName: string;
    reviewing: boolean;
    onReview: () => void;
    onCancelReview: () => void;
    onConfirm: (event: FormEvent) => void;
    processing: boolean;
    progress: Array<{ page: number; label: string; state: string }>;
}) {
    const locked = form.locked || finalized;
    const employer = employerOf(form);
    const certification = form.employerCertification ?? { signed: false, printedName: '', title: '', certifiedOn: '' };
    const recorded = form.certification.latest;
    const evaluations = form.evaluations ?? [];
    const progressEvaluation = evaluations.find((item) => item.kind !== 'final');
    const finalEvaluation = evaluations.find((item) => item.kind === 'final');
    const nameTitle = [certification.printedName, certification.title].filter(Boolean).join(', ');

    function text(key: keyof StudentDraft) {
        return <input className={line} value={String(data[key] ?? '')} disabled={locked} onChange={(event) => setData(key, event.target.value)} />;
    }

    return (
        <div className="mt-4">
            <ol className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                {progress.map((item) => (
                    <li key={item.page}>
                        <button
                            type="button"
                            aria-current={current === item.page ? 'page' : undefined}
                            className={`flex min-h-11 w-full flex-col items-start rounded-md border px-2 py-2 text-left ${current === item.page ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-300 bg-white text-neutral-900'}`}
                            onClick={() => onPage(item.page)}
                        >
                            <span className="text-[11px] font-semibold tracking-wide uppercase">Page {item.page}</span>
                            <span className="text-sm leading-5 font-medium">{item.label}</span>
                            <span className={`text-[11px] ${current === item.page ? 'text-neutral-200' : 'text-neutral-500'}`}>{item.state}</span>
                        </button>
                    </li>
                ))}
            </ol>

            <article className="mx-auto mt-4 w-full max-w-[860px] border border-neutral-800 bg-white text-neutral-950 shadow-sm">
                <header className="border-b border-neutral-800 px-3 py-3 text-center">
                    <p className="text-[11px] font-semibold tracking-wide uppercase">Department of Homeland Security</p>
                    <p className="text-[11px] uppercase">U.S. Immigration and Customs Enforcement</p>
                    <h1 className="mt-1 text-base font-bold sm:text-lg">Training Plan for STEM OPT Students</h1>
                    <p className="mt-1 text-[11px] text-neutral-600">
                        Science, Technology, Engineering & Mathematics (STEM) Optional Practical Training (OPT)
                    </p>
                    <p className="mt-2 text-xs text-neutral-600">
                        Case {form.reference}. This screen follows the official form. The PDF is still generated on the original five-page form.
                    </p>
                </header>

                {current === 1 && (
                    <div>
                        <SectionBar>Section 1: Student information (Completed by student)</SectionBar>
                        <div className="grid grid-cols-1 sm:grid-cols-3">
                            <Cell label="Student name — surname / primary name" hint="Student" error={error('surname')}>
                                {text('surname')}
                            </Cell>
                            <Cell label="Given name" hint="Student" error={error('given_name')} className="sm:border-l sm:border-neutral-800">
                                {text('given_name')}
                            </Cell>
                            <Cell label="Middle name" hint="Student" error={error('middle_name')} className="sm:border-l sm:border-neutral-800">
                                {text('middle_name')}
                            </Cell>
                        </div>
                        <Cell label="Student email address" hint="Student" error={error('email')}>
                            {text('email')}
                        </Cell>
                        <div className="grid grid-cols-1 sm:grid-cols-2">
                            <Cell label="Name of school recommending STEM OPT" hint="Student" error={error('school_recommending')}>
                                {text('school_recommending')}
                            </Cell>
                            <Cell
                                label="Name of school where STEM degree was earned"
                                hint="Student"
                                error={error('school_degree')}
                                className="sm:border-l sm:border-neutral-800"
                            >
                                {text('school_degree')}
                            </Cell>
                        </div>
                        <Cell
                            label="SEVIS school code of school recommending STEM OPT (including 3-digit suffix)"
                            hint="Student"
                            error={error('sevis_school_code')}
                        >
                            {text('sevis_school_code')}
                        </Cell>
                        <div className="grid grid-cols-1 sm:grid-cols-3">
                            <Cell label="Designated school official (DSO) name" hint="Student" error={error('dso_name')}>
                                {text('dso_name')}
                            </Cell>
                            <Cell label="DSO email" hint="Student" error={error('dso_email')} className="sm:border-l sm:border-neutral-800">
                                {text('dso_email')}
                            </Cell>
                            <Cell label="DSO phone number" hint="Student" error={error('dso_phone')} className="sm:border-l sm:border-neutral-800">
                                {text('dso_phone')}
                            </Cell>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2">
                            <Cell label="Student SEVIS ID number" hint="Student" error={error('sevis_id')}>
                                {text('sevis_id')}
                            </Cell>
                            <Cell
                                label="STEM OPT requested period (mm-dd-yyyy)"
                                hint="Student"
                                className="sm:border-l sm:border-neutral-800"
                                error={error('opt_start') || error('opt_end')}
                            >
                                <label className="mt-1 block text-xs text-neutral-600">
                                    From
                                    <input
                                        className={line}
                                        type="date"
                                        value={data.opt_start}
                                        disabled={locked}
                                        onChange={(event) => setData('opt_start', event.target.value)}
                                    />
                                </label>
                                <label className="mt-2 block text-xs text-neutral-600">
                                    To
                                    <input
                                        className={line}
                                        type="date"
                                        value={data.opt_end}
                                        disabled={locked}
                                        onChange={(event) => setData('opt_end', event.target.value)}
                                    />
                                </label>
                            </Cell>
                        </div>
                        <Cell
                            label="Qualifying major and classification of instructional programs (CIP) code"
                            hint="Student"
                            error={error('major_cip')}
                        >
                            {text('major_cip')}
                        </Cell>
                        <div className="grid grid-cols-1 sm:grid-cols-2">
                            <Cell label="Level/type of qualifying degree" hint="Student" error={error('degree_level')}>
                                <select
                                    className={line}
                                    value={data.degree_level}
                                    disabled={locked}
                                    onChange={(event) => setData('degree_level', event.target.value)}
                                >
                                    <option value="">Select</option>
                                    <option value="Bachelor">Bachelor's</option>
                                    <option value="Master">Master's</option>
                                    <option value="Doctorate">Doctorate</option>
                                </select>
                            </Cell>
                            <Cell
                                label="Date awarded (mm-dd-yyyy)"
                                hint="Student"
                                error={error('degree_awarded_on')}
                                className="sm:border-l sm:border-neutral-800"
                            >
                                <input
                                    className={line}
                                    type="date"
                                    value={data.degree_awarded_on}
                                    disabled={locked}
                                    onChange={(event) => setData('degree_awarded_on', event.target.value)}
                                />
                            </Cell>
                        </div>
                        <Cell label="Based on prior degree?" hint="Student" error={error('prior_degree')}>
                            <div className="mt-2 flex flex-wrap gap-4 text-sm">
                                {['yes', 'no'].map((choice) => (
                                    <label key={choice} className="flex min-h-11 items-center gap-2">
                                        <input
                                            type="radio"
                                            name="prior_degree"
                                            value={choice}
                                            checked={data.prior_degree === choice}
                                            disabled={locked}
                                            onChange={() => setData('prior_degree', choice)}
                                        />
                                        {choice === 'yes' ? 'Yes' : 'No'}
                                    </label>
                                ))}
                            </div>
                        </Cell>
                        <Cell label="Employment authorization number" hint="Student" error={error('ead')}>
                            {text('ead')}
                        </Cell>
                        <Plain>A draft saves Section 1 without signing Section 2. Submit is a separate certification step.</Plain>
                    </div>
                )}

                {current === 2 && (
                    <div>
                        <SectionBar>Section 2: Student certification</SectionBar>
                        <Plain>
                            <p>
                                I declare and affirm under penalty of perjury that the statements and information made herein are true and correct to
                                the best of my knowledge, information and belief. I understand that the law provides severe penalties for knowingly
                                and willfully falsifying or concealing a material fact, or using any false document in the submission of this form.
                            </p>
                            <p className="mt-3 font-semibold">I certify that:</p>
                            <ol className="mt-2 list-decimal space-y-2 pl-5">
                                {form.statements.map((statement) => (
                                    <li key={statement}>{statement}</li>
                                ))}
                            </ol>
                        </Plain>
                        {locked && recorded ? (
                            <Plain>
                                <p>Certification version {recorded.version} is stored. Section 2 is not signed again from this page.</p>
                                <p className="mt-2">Printed name of student: {recorded.printedName}</p>
                                <p>
                                    Date: {recorded.certifiedOn} ({recorded.timezone})
                                </p>
                                <p>Recorded at: {recorded.certifiedAt}</p>
                            </Plain>
                        ) : (
                            <div>
                                <Cell label="Acknowledgment" hint="Student" error={error('confirm_certification')}>
                                    <label className="flex min-h-11 items-start gap-2 text-sm">
                                        <input
                                            className="mt-1"
                                            type="checkbox"
                                            checked={data.confirm_certification}
                                            disabled={finalized}
                                            onChange={(event) => setData('confirm_certification', event.target.checked)}
                                        />
                                        <span>I confirm the five Section 2 statements above.</span>
                                    </label>
                                </Cell>
                                <Cell label="Printed name of student" hint="From Section 1, including the middle name" error={error('confirm_name')}>
                                    <p className="min-h-11 border-b border-neutral-800 py-1 text-base font-medium sm:min-h-9 sm:text-sm">
                                        {printedName || 'Surname, Given name'}
                                    </p>
                                    <label className="mt-3 flex min-h-11 items-start gap-2 text-sm">
                                        <input
                                            className="mt-1"
                                            type="checkbox"
                                            checked={data.confirm_name}
                                            disabled={finalized}
                                            onChange={(event) => setData('confirm_name', event.target.checked)}
                                        />
                                        <span>I confirm the printed name above.</span>
                                    </label>
                                </Cell>
                                <Cell
                                    label="Signature of student"
                                    hint="Draw the signature. A typed name is not accepted."
                                    error={error('signature')}
                                >
                                    <SignaturePad disabled={finalized} onChange={(file) => setData('signature', file)} />
                                    <p className="mt-2 text-xs text-neutral-600">
                                        The form date will be {form.localDate} in {form.timezone}. You do not enter the date.
                                    </p>
                                </Cell>
                                {reviewing && (
                                    <Plain>
                                        <h3 className="font-semibold">Review before submission</h3>
                                        <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                                            <div>
                                                <dt className="text-xs text-neutral-500">Name</dt>
                                                <dd>{printedName || '—'}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-neutral-500">Email</dt>
                                                <dd>{data.email || '—'}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-neutral-500">School recommending STEM OPT</dt>
                                                <dd>{data.school_recommending || '—'}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-neutral-500">SEVIS ID</dt>
                                                <dd>{data.sevis_id || '—'}</dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-neutral-500">STEM OPT period</dt>
                                                <dd>
                                                    {displayDate(data.opt_start)} to {displayDate(data.opt_end)}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-neutral-500">Degree</dt>
                                                <dd>
                                                    {data.degree_level || '—'} · {data.major_cip || '—'}
                                                </dd>
                                            </div>
                                        </dl>
                                        <p className="mt-3 text-sm">
                                            Confirming submits Section 1 and this Section 2 certification together. It does not sign Section 4 or
                                            Section 6, and it does not send the form to a school or agency.
                                        </p>
                                        <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                                            <button
                                                className="min-h-11 rounded-md border border-neutral-800 px-4 text-sm"
                                                type="button"
                                                onClick={onCancelReview}
                                            >
                                                Back to edit
                                            </button>
                                            <button
                                                className="min-h-11 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white"
                                                type="button"
                                                disabled={processing}
                                                onClick={onConfirm}
                                            >
                                                Confirm and submit certification
                                            </button>
                                        </div>
                                    </Plain>
                                )}
                                {!reviewing && !finalized && (
                                    <Plain>
                                        <button
                                            className="min-h-11 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white"
                                            type="button"
                                            disabled={processing}
                                            onClick={onReview}
                                        >
                                            Review and submit
                                        </button>
                                    </Plain>
                                )}
                            </div>
                        )}
                        {form.certification.history.length > 0 && (
                            <Plain>
                                <p className="font-semibold">Certification history</p>
                                <ul className="mt-2 space-y-1">
                                    {form.certification.history.map((item) => (
                                        <li key={item.version}>
                                            Version {item.version}: {item.printedName} · {item.certifiedOn} · {item.timezone}
                                        </li>
                                    ))}
                                </ul>
                            </Plain>
                        )}
                    </div>
                )}

                {current === 3 && (
                    <div>
                        <SectionBar>Section 3: Employer information (Completed by employer)</SectionBar>
                        <Plain>These fields are read-only. HR completes Section 3. A student cannot change them.</Plain>
                        <div className="grid grid-cols-1 sm:grid-cols-3">
                            <Cell label="Employer name" hint="Employer">
                                <Read value={employer.legalName || data.employer_name} />
                            </Cell>
                            <Cell label="Street address" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={employer.street} />
                            </Cell>
                            <Cell label="Suite" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={employer.suite} />
                            </Cell>
                        </div>
                        <Cell label="Employer website URL" hint="Employer">
                            <Read value={employer.website} />
                        </Cell>
                        <div className="grid grid-cols-1 sm:grid-cols-3">
                            <Cell label="City" hint="Employer">
                                <Read value={employer.city} />
                            </Cell>
                            <Cell label="State" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={employer.state} />
                            </Cell>
                            <Cell label="ZIP code" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={employer.zip} />
                            </Cell>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-3">
                            <Cell label="Employer ID number (EIN)" hint="Employer">
                                <Read value={employer.ein} />
                            </Cell>
                            <Cell label="Number of full-time employees in the U.S." hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={employer.employeeCount} />
                            </Cell>
                            <Cell
                                label="North American Industry Classification System (NAICS) code"
                                hint="Employer"
                                className="sm:border-l sm:border-neutral-800"
                            >
                                <Read value={employer.naics} />
                            </Cell>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2">
                            <Cell label="OPT hours per week (must be at least 20 hours/week)" hint="Employer">
                                <Read value={employer.optHours} />
                            </Cell>
                            <Cell
                                label="Compensation — annual salary amount and frequency"
                                hint="Employer"
                                className="sm:border-l sm:border-neutral-800"
                            >
                                <Read value={salaryText(employer)} />
                            </Cell>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2">
                            <Cell label="Start date of employment" hint="Employer">
                                <Read value={displayDate(employer.startDate)} />
                            </Cell>
                            <Cell label="Other compensation" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={employer.otherCompensation} />
                            </Cell>
                        </div>

                        <SectionBar>Section 5: Training plan for STEM OPT students</SectionBar>
                        <Cell label="Student name (surname/primary name, given name)" hint="From Section 1">
                            <Read value={form.training.studentName} />
                        </Cell>
                        <Cell label="Employer name" hint="From Section 3">
                            <Read value={data.employer_name} />
                        </Cell>
                        <div className="bg-neutral-200 px-3 py-1 text-center text-[11px] font-bold uppercase">Employer site information</div>
                        <div className="grid grid-cols-1 sm:grid-cols-2">
                            <Cell label="Site name" hint="Employer">
                                <Read value={data.site_name} />
                            </Cell>
                            <Cell label="Site address (street, city, state, ZIP)" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={data.site_address} />
                            </Cell>
                            <Cell label="Name of official" hint="Employer">
                                <Read value={data.official_name} />
                            </Cell>
                            <Cell label="Official's title" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={data.official_title} />
                            </Cell>
                            <Cell label="Official's email" hint="Employer">
                                <Read value={data.official_email} />
                            </Cell>
                            <Cell label="Official's phone number" hint="Employer" className="sm:border-l sm:border-neutral-800">
                                <Read value={data.official_phone} />
                            </Cell>
                        </div>
                        <Cell
                            label="Student role"
                            hint={form.locked ? 'Locked after submission' : 'Student, until you submit'}
                            error={error('student_role')}
                        >
                            <textarea
                                className={area}
                                rows={4}
                                readOnly={locked}
                                value={data.student_role}
                                onChange={(event) => setData('student_role', event.target.value)}
                            />
                        </Cell>
                        <Cell label="Goals and objectives" hint="Shared. You and HR can update this field." error={error('goals')}>
                            {finalized ? (
                                <Read value={data.goals} />
                            ) : (
                                <textarea className={area} rows={4} value={data.goals} onChange={(event) => setData('goals', event.target.value)} />
                            )}
                        </Cell>
                        <Cell label="Employer oversight" hint="Employer">
                            <textarea className={area} rows={4} readOnly value={data.oversight} />
                        </Cell>
                        <Cell label="Measures and assessments" hint="Employer">
                            <textarea className={area} rows={4} readOnly value={data.measures} />
                        </Cell>
                        <Cell label="Additional remarks (optional)" hint="Employer">
                            <textarea className={area} rows={3} readOnly value={data.remarks} />
                        </Cell>
                        {(form.goalHistory ?? []).length > 0 && (
                            <Plain>
                                <p className="font-semibold">Goals and objectives history</p>
                                <ul className="mt-2 space-y-2">
                                    {form.goalHistory?.map((revision, index) => (
                                        <li key={`${revision.at}-${index}`}>
                                            <p>
                                                {revision.at} · {revision.editor} ({revision.actor})
                                            </p>
                                            <p className="text-neutral-500">Previous: {revision.previous || '—'}</p>
                                            <p>New: {revision.next || '—'}</p>
                                        </li>
                                    ))}
                                </ul>
                            </Plain>
                        )}
                    </div>
                )}

                {current === 4 && (
                    <div>
                        <SectionBar>Section 4: Employer certification</SectionBar>
                        <Plain>
                            <p>
                                I certify on behalf of the employer that this Training Plan for STEM OPT Students (&quot;Plan&quot;) is approved and
                                that:
                            </p>
                            <ol className="mt-2 list-decimal space-y-2 pl-5">
                                {employerCertificationPoints.map((point) => (
                                    <li key={point}>{point}</li>
                                ))}
                            </ol>
                            <p className="mt-3">
                                Only a Company Admin can apply the employer signature. This page does not accept a student signature.
                            </p>
                        </Plain>
                        <SignatureLines
                            signed={certification.signed}
                            nameTitle={nameTitle}
                            date={certification.certifiedOn}
                            organization={employer.legalName}
                            showOrganization
                        />
                        <SectionBar>Section 6: Employer official certification</SectionBar>
                        <Plain>
                            <p>Employer official with signatory authority — I certify that:</p>
                            <ol className="mt-2 list-decimal space-y-2 pl-5">
                                {sectionSixPoints.map((point) => (
                                    <li key={point}>{point}</li>
                                ))}
                            </ol>
                        </Plain>
                        <SignatureLines
                            signed={certification.signed}
                            nameTitle={nameTitle}
                            date={certification.certifiedOn}
                            organization=""
                            showOrganization={false}
                        />
                        <Plain>The Privacy Act and Paperwork Reduction Act statements remain on the official PDF. They are not editable here.</Plain>
                    </div>
                )}

                {current === 5 && (
                    <div>
                        <EvaluationBlock title="Evaluation on student progress" evaluation={progressEvaluation} />
                        <EvaluationBlock title="Final evaluation on student progress" evaluation={finalEvaluation} />
                        <Plain>
                            These sections stay blank until evaluation data is entered through the evaluation workflow. This page does not collect a
                            signature.
                        </Plain>
                    </div>
                )}

                <footer className="flex items-center justify-between px-3 py-2 text-[11px] text-neutral-600">
                    <span>ICE Form I-983 (7/16)</span>
                    <span>
                        Page {current} of 5 · {finalized ? 'Finalized' : form.locked ? 'Submitted' : 'Draft'}
                    </span>
                </footer>
            </article>
        </div>
    );
}

function SignatureLines({
    signed,
    nameTitle,
    date,
    organization,
    showOrganization,
}: {
    signed: boolean;
    nameTitle: string;
    date: string;
    organization: string;
    showOrganization: boolean;
}) {
    return (
        <div>
            <Cell label="Signature of employer official with signatory authority" hint="Employer">
                <p className="min-h-11 border-b border-neutral-800 py-1 text-sm">
                    {signed ? 'Signature on file with the employer certification' : '—'}
                </p>
            </Cell>
            <Cell label="Printed name and title of employer official with signatory authority" hint="Employer">
                <Read value={nameTitle} />
            </Cell>
            <div className={showOrganization ? 'grid grid-cols-1 sm:grid-cols-2' : ''}>
                <Cell label="Date (mm-dd-yyyy)" hint="Employer">
                    <Read value={date} />
                </Cell>
                {showOrganization && (
                    <Cell label="Printed name of employing organization" hint="From Section 3" className="sm:border-l sm:border-neutral-800">
                        <Read value={organization} />
                    </Cell>
                )}
            </div>
        </div>
    );
}

function EvaluationBlock({ title, evaluation }: { title: string; evaluation?: EvaluationView }) {
    return (
        <div>
            <SectionBar>{title}</SectionBar>
            <Plain>
                Provide a self-evaluation of your performance, using the measures previously identified in applying and acquiring new knowledge,
                skills, and competencies identified in the Training Plan for STEM OPT Students.
            </Plain>
            <div className="grid grid-cols-1 sm:grid-cols-2">
                <Cell label="Range of evaluation dates — from (mm-dd-yyyy)">
                    <Read value={evaluation?.from ?? ''} />
                </Cell>
                <Cell label="To (mm-dd-yyyy)" className="sm:border-l sm:border-neutral-800">
                    <Read value={evaluation?.to ?? ''} />
                </Cell>
            </div>
            <Cell label="Evaluation">
                <Read value={evaluation?.narrative ?? ''} />
            </Cell>
            <Cell label="Signature of student">
                <Read value={evaluation?.studentSigned ? `Signed ${evaluation.studentSignedOn}` : ''} />
            </Cell>
            <Cell label="Signature of employer official with signatory authority">
                <Read value={evaluation?.employerSigned ? `Signed ${evaluation.employerSignedOn}` : ''} />
            </Cell>
        </div>
    );
}
