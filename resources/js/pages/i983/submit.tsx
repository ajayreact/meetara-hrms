import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { pageForError, StudentDocument, StudentDraft, StudentFormView } from './student-form/document';

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

function validate(data: StudentDraft, submit: boolean): Record<string, string> {
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
    if (data.signature === null) {
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

    const firstServerError = Object.keys(serverErrors)[0] ?? '';
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
        const found = validate(page.data, submit);
        setLocalErrors(found);
        if (Object.keys(found).length > 0) {
            setCurrent(pageForError(Object.keys(found)[0]));
            setReviewing(false);

            return;
        }
        page.transform((data) => ({
            ...data,
            submit,
            signature: submit ? data.signature : null,
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
    const employerSigned = form.employerCertification?.signed === true || finalized;
    const evaluationRecorded = (form.evaluations ?? []).some((item) => item.narrative.trim() !== '');
    const progress = [
        { page: 1, label: 'Student information', state: sectionOneComplete ? 'Complete' : 'Pending' },
        { page: 2, label: 'Student certification', state: certificationComplete ? 'Complete' : 'Pending' },
        { page: 3, label: 'Employer and training', state: roleComplete ? 'Student role complete' : 'Student role pending' },
        { page: 4, label: 'Employer certification', state: employerSigned ? 'Signed' : 'Waiting for employer' },
        { page: 5, label: 'Evaluations', state: evaluationRecorded ? 'Recorded' : 'Blank' },
    ];
    const banner = Object.values(localErrors)[0] || Object.values(serverErrors)[0] || '';

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
                            This link expires {form.expiresAt}. Save a draft and return with the same link. Submitting saves Section 1 and your
                            Section 2 certification together. It locks Section 1 and Student Role. Goals and Objectives stay shared. It does not sign
                            Section 4 or Section 6, and it does not send the form to a school or agency.
                        </p>
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
                        error={(key) => localErrors[key] || serverErrors[key] || ''}
                        printedName={printedName}
                        reviewing={reviewing}
                        onReview={() => {
                            const found = validate(page.data, true);
                            setLocalErrors(found);
                            if (Object.keys(found).length > 0) {
                                setCurrent(pageForError(Object.keys(found)[0]));
                                setReviewing(false);

                                return;
                            }
                            setReviewing(true);
                            setCurrent(2);
                        }}
                        onCancelReview={() => setReviewing(false)}
                        onConfirm={(event) => send(event, true)}
                        processing={page.processing}
                        progress={progress}
                    />
                    {!finalized && (
                        <div className="mx-auto mt-4 flex max-w-[860px] flex-col gap-2 sm:flex-row">
                            <button
                                className="min-h-11 rounded-md border border-neutral-300 px-4 text-sm"
                                type="button"
                                disabled={current === 1}
                                onClick={() => setCurrent((value) => Math.max(1, value - 1))}
                            >
                                Previous
                            </button>
                            <button
                                className="min-h-11 rounded-md border border-neutral-300 px-4 text-sm"
                                type="button"
                                disabled={current === 5}
                                onClick={() => setCurrent((value) => Math.min(5, value + 1))}
                            >
                                Next
                            </button>
                            <button className="min-h-11 rounded-md border border-neutral-800 px-4 text-sm" type="submit" disabled={page.processing}>
                                Save draft
                            </button>
                        </div>
                    )}
                </form>
            </main>
        </>
    );
}
