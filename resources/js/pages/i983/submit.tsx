import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { pageForError, StudentDocument, StudentDraft, StudentFormView } from './student-form/document';
import { retainedStudentSignature } from './student-form/signature-source';

const requiredFields: Array<keyof StudentDraft> = [
    'surname',
    'given_name',
    'email',
    'school_recommending',
    'school_degree',
    'sevis_school_code',
    'dso_name',
    'dso_email',
    'dso_phone',
    'sevis_id',
    'opt_start',
    'opt_end',
    'major_cip',
    'degree_level',
    'degree_awarded_on',
    'ead',
];

const fieldLabels: Record<string, string> = {
    surname: 'Surname / primary name',
    given_name: 'Given name',
    email: 'Student email address',
    school_recommending: 'School recommending STEM OPT',
    school_degree: 'School where the STEM degree was earned',
    sevis_school_code: 'SEVIS school code',
    dso_name: 'DSO name',
    dso_email: 'DSO email',
    dso_phone: 'DSO phone number',
    sevis_id: 'Student SEVIS ID',
    opt_start: 'STEM OPT start date',
    opt_end: 'STEM OPT end date',
    major_cip: 'Qualifying major and CIP code',
    degree_level: 'Degree level',
    degree_awarded_on: 'Degree award date',
    prior_degree: 'Prior degree',
    ead: 'Employment authorization number',
    confirm_certification: 'Section 2 acknowledgment',
    confirm_name: 'Printed name confirmation',
    signature: 'Student signature',
};

function printedCertificationName(surname: string, givenName: string, middleName: string): string {
    const rest = [givenName, middleName]
        .map((part) => part.trim())
        .filter(Boolean)
        .join(' ');
    const family = surname.trim();
    if (family === '') {
        return rest;
    }
    if (rest === '') {
        return family;
    }

    return `${family}, ${rest}`;
}

function emailLooksValid(value: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

function validate(data: StudentDraft, submit: boolean, signatureReady: boolean): Record<string, string> {
    const errors: Record<string, string> = {};
    if (data.opt_start !== '' && data.opt_end !== '' && data.opt_end < data.opt_start) {
        errors.opt_end = 'The STEM OPT end date must be on or after the start date.';
    }
    if (data.email.trim() !== '' && !emailLooksValid(data.email)) {
        errors.email = 'Enter a valid email address.';
    }
    if (data.dso_email.trim() !== '' && !emailLooksValid(data.dso_email)) {
        errors.dso_email = 'Enter a valid email address.';
    }
    if (!submit) {
        return errors;
    }
    requiredFields.forEach((field) => {
        if (String(data[field] ?? '').trim() === '') {
            errors[field] = `Complete ${fieldLabels[field] ?? 'this field'} before submitting.`;
        }
    });
    if (data.prior_degree !== 'yes' && data.prior_degree !== 'no') {
        errors.prior_degree = 'Select whether this is based on a prior degree.';
    }
    if (!data.confirm_certification) {
        errors.confirm_certification = 'Confirm the five Section 2 statements.';
    }
    if (!data.confirm_name) {
        errors.confirm_name = 'Confirm the printed name.';
    }
    if (!signatureReady) {
        errors.signature = 'Draw your signature before submitting.';
    }

    return errors;
}

function filled(value: string): boolean {
    return value.trim() !== '';
}

export default function I983Submit({ token, form, notice }: { token: string; form: StudentFormView; notice?: string }) {
    const finalized = form.status === 'finalized';
    const serverErrors = usePage().props.errors as Record<string, string>;
    const [current, setCurrent] = useState(1);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [reviewing, setReviewing] = useState(false);
    const [cleared, setCleared] = useState(false);
    const page = useForm({
        surname: form.student.surname,
        given_name: form.student.givenName,
        middle_name: form.student.middleName,
        email: form.student.email,
        school_recommending: form.student.schoolRecommending,
        school_degree: form.student.schoolDegree,
        sevis_school_code: form.student.sevisSchoolCode,
        dso_name: form.student.dsoName,
        dso_email: form.student.dsoEmail,
        dso_phone: form.student.dsoPhone,
        sevis_id: form.student.sevisId,
        opt_start: form.student.optStart,
        opt_end: form.student.optEnd,
        major_cip: form.student.majorCip,
        degree_level: form.student.degreeLevel,
        degree_awarded_on: form.student.degreeAwardedOn,
        prior_degree: form.student.priorDegree,
        ead: form.student.ead,
        employer_name: form.training.employerName,
        site_name: form.training.siteName,
        site_address: form.training.siteAddress,
        official_name: form.training.officialName,
        official_title: form.training.officialTitle,
        official_email: form.training.officialEmail,
        official_phone: form.training.officialPhone,
        student_role: form.training.studentRole,
        goals: form.training.goals,
        oversight: form.training.oversight,
        measures: form.training.measures,
        remarks: form.training.remarks,
        fax: '',
        submit: false,
        confirm_certification: false,
        confirm_name: false,
        signature: null,
    });
    const printedName = printedCertificationName(page.data.surname, page.data.given_name, page.data.middle_name);
    const signatureSource = retainedStudentSignature(page.data.signature !== null, form.signatureOnFile, cleared);
    const signatureUrl = signatureSource === 'stored' ? `/i983/s/${token}/signature` : null;

    const employerAddressKeys = ['street', 'suite', 'city', 'state', 'zip', 'site_address'];
    const studentServerErrors = Object.fromEntries(Object.entries(serverErrors).filter(([key]) => !employerAddressKeys.includes(key)));
    const firstServerError = Object.keys(studentServerErrors)[0] ?? '';
    useEffect(() => {
        if (firstServerError !== '') {
            setCurrent(pageForError(firstServerError));
            setReviewing(false);
        }
    }, [firstServerError]);

    function send(event: FormEvent, submit: boolean) {
        event.preventDefault();
        if (finalized) {
            return;
        }
        const found = validate(page.data, submit, signatureSource !== 'empty');
        setLocalErrors(found);
        if (Object.keys(found).length > 0) {
            setCurrent(pageForError(Object.keys(found)[0]));
            setReviewing(false);

            return;
        }
        page.transform((data) => ({
            ...data,
            submit,
            signature: data.signature,
            clear_signature: !submit && cleared && data.signature === null ? '1' : '0',
            confirm_certification: submit ? data.confirm_certification : false,
            confirm_name: submit ? data.confirm_name : false,
        }));
        page.post(`/i983/s/${token}`, { forceFormData: true });
    }

    const sectionOneComplete =
        requiredFields.every((field) => filled(String(page.data[field] ?? ''))) &&
        (page.data.prior_degree === 'yes' || page.data.prior_degree === 'no') &&
        page.data.opt_end >= page.data.opt_start;
    const certificationComplete = form.locked && form.certification.latest !== null;
    const roleComplete = filled(page.data.student_role) || form.locked;
    const progress = [
        { page: 1, label: 'Student information', state: sectionOneComplete ? 'Complete' : 'Pending' },
        { page: 2, label: 'Student certification', state: certificationComplete ? 'Complete' : 'Pending' },
        { page: 3, label: 'Your training responses', state: roleComplete ? 'Complete' : 'Pending' },
    ];
    const banner = Object.values(localErrors)[0] || Object.values(studentServerErrors)[0] || '';

    return (
        <>
            <Head title={finalized ? `I-983 Finalized – ${form.reference}` : `I-983 ${form.reference}`} />
            <main className="mx-auto max-w-5xl overflow-x-hidden px-3 py-6 sm:px-4 sm:py-8">
                {finalized ? (
                    <section className="rounded-md border border-neutral-300 bg-white p-4">
                        <p className="text-xs font-semibold tracking-wide text-neutral-500 uppercase">{form.reference}</p>
                        <h1 className="mt-1 text-2xl font-semibold text-neutral-950">I-983 Finalized – Employer Signed</h1>
                        <p className="mt-2 text-sm leading-6 text-neutral-600">
                            The employer has completed and signed this document. It has been finalized and cannot be edited through this link.
                        </p>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-neutral-500">Student</dt>
                                <dd className="font-medium">{form.training.studentName}</dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500">Employer</dt>
                                <dd className="font-medium">{form.training.employerName}</dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500">Finalized</dt>
                                <dd className="font-medium">{form.finalizedAt}</dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500">Status</dt>
                                <dd className="font-medium">Employer completed and signed</dd>
                            </div>
                        </dl>
                        <div className="mt-5 flex flex-col gap-3 sm:flex-row">
                            <a
                                className="min-h-11 rounded-md bg-neutral-950 px-4 py-2 text-center text-sm font-medium text-white"
                                href={`/i983/s/${token}/final`}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Preview final I-983
                            </a>
                            <a
                                className="min-h-11 rounded-md border border-neutral-300 px-4 py-2 text-center text-sm font-medium"
                                href={`/i983/s/${token}/final/download`}
                            >
                                Download final I-983
                            </a>
                        </div>
                    </section>
                ) : (
                    <div>
                        <h1 className="text-2xl font-semibold text-neutral-950">STEM OPT Form I-983</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-neutral-600">
                            This link expires {form.expiresAt}. Save a draft and return with the same link. Review your information, then submit it to
                            your employer. You cannot change employer details. After you submit, Section 1 and Student Role stay locked unless your
                            employer reopens them.
                        </p>
                        {form.locked && (
                            <p className="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                                Submitted to your employer. This form cannot be submitted again unless the employer asks for a correction.
                            </p>
                        )}
                    </div>
                )}
                {form.correctionReason && (
                    <p className="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-950">HR asked for a correction: {form.correctionReason}</p>
                )}
                {notice && <p className="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}
                {banner && <p className="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-800">{banner}</p>}
                <form onSubmit={(event) => send(event, false)}>
                    <input
                        className="hidden"
                        tabIndex={-1}
                        autoComplete="off"
                        value={page.data.fax}
                        onChange={(event) => page.setData('fax', event.target.value)}
                    />
                    <StudentDocument
                        current={current}
                        onPage={(next) => {
                            setCurrent(next);
                            setReviewing(false);
                        }}
                        data={page.data}
                        setData={(key, value) => {
                            if (key === 'signature') {
                                setCleared(value === null);
                            }
                            page.setData(key as 'surname', value as string);
                            setLocalErrors((current) => {
                                if (current[key] === undefined) {
                                    return current;
                                }
                                const next = { ...current };
                                delete next[key];

                                return next;
                            });
                        }}
                        form={form}
                        finalized={finalized}
                        error={(key) => localErrors[key] || studentServerErrors[key] || ''}
                        printedName={printedName}
                        reviewing={reviewing}
                        progress={progress}
                        signatureUrl={signatureUrl}
                    />
                    {!finalized && (
                        <div className="mx-auto mt-4 flex max-w-[860px] flex-col gap-2 sm:flex-row">
                            <button
                                className="min-h-11 rounded-md border border-neutral-300 px-4 text-sm"
                                type="button"
                                disabled={current === 1}
                                onClick={() => {
                                    setReviewing(false);
                                    setCurrent((value) => Math.max(1, value - 1));
                                }}
                            >
                                Previous
                            </button>
                            {current < 3 && (
                                <button
                                    className="min-h-11 rounded-md border border-neutral-300 px-4 text-sm"
                                    type="button"
                                    onClick={() => {
                                        setReviewing(false);
                                        setCurrent((value) => Math.min(3, value + 1));
                                    }}
                                >
                                    Next
                                </button>
                            )}
                            <button className="min-h-11 rounded-md border border-neutral-800 px-4 text-sm" type="submit" disabled={page.processing}>
                                Save Draft
                            </button>
                            {current === 3 && !form.locked && (
                                <>
                                    <button
                                        className="min-h-11 rounded-md border border-neutral-800 px-4 text-sm"
                                        type="button"
                                        onClick={() => {
                                            const found = validate(page.data, true, signatureSource !== 'empty');
                                            setLocalErrors(found);
                                            if (Object.keys(found).length > 0) {
                                                setCurrent(pageForError(Object.keys(found)[0]));
                                                setReviewing(false);

                                                return;
                                            }
                                            setReviewing(true);
                                            setCurrent(3);
                                        }}
                                    >
                                        Review
                                    </button>
                                    <button
                                        className="min-h-11 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white"
                                        type="button"
                                        disabled={page.processing}
                                        onClick={(event) => send(event, true)}
                                    >
                                        Submit to Employer
                                    </button>
                                </>
                            )}
                        </div>
                    )}
                </form>
            </main>
        </>
    );
}
