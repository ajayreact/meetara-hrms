import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import { SectionCard } from '@/components/hrms/section-card';
import type { HrmsPageProps } from '@/types/hrms';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

interface StudentInfo {
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

interface EmployerInfo {
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
    hourlyRate: string;
    salary: string;
    salaryFrequency: string;
    annualCompensation: string;
    otherCompensation: string;
    startDate: string;
    finalizedAt: string;
}

interface TrainingInfo {
    studentName: string;
    employerName: string;
    siteName: string;
    siteAddress: string;
    workArrangement: string;
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

interface Revision {
    field: string;
    actor: string;
    editor: string;
    previous: string;
    next: string;
    at: string;
}

interface Correction {
    reason: string;
    requestedAt: string;
}

interface DocumentVersion {
    id: number;
    kind: string;
    version: number;
    sha256: string;
    createdAt: string;
    createdBy: string;
    bytes: number;
    sourceDocumentId: number | null;
}

interface Signatory {
    name: string;
    title: string;
    hasImage: boolean;
}

const control = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm';

const documentLabels: Record<string, string> = {
    student_snapshot: 'Student-submitted',
    hr_working: 'HR working',
    employer_signed: 'Employer signed',
    final_signed: 'Final signed',
};
const lockedControl = `${control} bg-slate-50 text-slate-700`;

interface StudentCertification {
    latest: { version: number; printedName: string; certifiedOn: string; timezone: string } | null;
    history: Array<{ version: number; printedName: string; certifiedOn: string; timezone: string }>;
}

interface Workflow {
    stage: string;
    label: string;
    canGenerateFinal: boolean;
    blockers: string[];
    steps: Array<{ key: string; label: string; state: 'complete' | 'current' | 'pending' }>;
}

export default function I983Show({ workspace, case: record, student, employer, training, link, linkUrl, revisions = [], correction = null, documents = [], signatory = { name: '', title: '', hasImage: false }, canSign = false, canPreviewDraft = false, studentCertification = { latest: null, history: [] }, workflow = { stage: 'student_submission', label: 'Student Submission', canGenerateFinal: false, blockers: [], steps: [] }, notice }: HrmsPageProps & {
    case: { id: number; reference: string; candidateName: string; candidateEmail: string; status: string; submittedAt: string };
    student: StudentInfo;
    employer: EmployerInfo;
    training: TrainingInfo;
    link: { active: boolean; expiresAt: string; revoked: boolean };
    linkUrl?: string;
    revisions?: Revision[];
    correction?: Correction | null;
    documents?: DocumentVersion[];
    signatory?: Signatory;
    canSign?: boolean;
    canPreviewDraft?: boolean;
    studentCertification?: StudentCertification;
    workflow?: Workflow;
    notice?: string;
}) {
    const employerForm = useForm({
        legal_name: employer.legalName,
        street: employer.street,
        suite: employer.suite,
        website: employer.website,
        city: employer.city,
        state: employer.state,
        zip: employer.zip,
        ein: employer.ein,
        employee_count: employer.employeeCount,
        naics: employer.naics,
        opt_hours: employer.optHours,
        hourly_rate: employer.hourlyRate,
        salary_frequency: employer.salaryFrequency || 'year',
        annual_compensation: employer.annualCompensation,
        other_compensation: employer.otherCompensation,
        start_date: employer.startDate,
    });
    const trainingForm = useForm({
        student_name: training.studentName,
        employer_name: training.employerName,
        site_name: training.siteName,
        site_address: training.siteAddress,
        work_arrangement: training.workArrangement,
        official_name: training.officialName,
        official_title: training.officialTitle,
        official_email: training.officialEmail,
        official_phone: training.officialPhone,
        student_role: training.studentRole,
        goals: training.goals,
        oversight: training.oversight,
        measures: training.measures,
        remarks: training.remarks,
    });
    const correctionForm = useForm({ reason: '' });
    const signatureForm = useForm<{ signatory_name: string; signatory_title: string; signature: File | null }>({
        signatory_name: signatory.name,
        signatory_title: signatory.title,
        signature: null,
    });
    const finalForm = useForm({ confirm: false });
    const [editing, setEditing] = useState<string | null>(null);

    useEffect(() => {
        if (trainingForm.data.employer_name !== training.employerName) {
            trainingForm.setData('employer_name', training.employerName);
        }
        // useForm returns a new object on every render, so only the name values belong in the dependency list.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [training.employerName, trainingForm.data.employer_name]);

    function saveEmployer(event: FormEvent) {
        event.preventDefault();
        employerForm.post(route('hrms.i983.employer', record.id));
    }

    function saveTraining(event: FormEvent) {
        event.preventDefault();
        trainingForm.post(route('hrms.i983.training', record.id));
    }

    function requestCorrection(event: FormEvent) {
        event.preventDefault();
        correctionForm.post(route('hrms.i983.correction', record.id));
    }

    function saveSignature(event: FormEvent) {
        event.preventDefault();
        signatureForm.post(route('hrms.i983.signature.save'));
    }

    function generateFinal(event: FormEvent) {
        event.preventDefault();
        finalForm.post(route('hrms.i983.final', record.id));
    }

    const facts = [
        ['Surname', student.surname],
        ['Given name', student.givenName],
        ['Middle name', student.middleName],
        ['Email', student.email],
        ['School recommending STEM OPT', student.schoolRecommending],
        ['School where STEM degree was earned', student.schoolDegree],
        ['SEVIS school code', student.sevisSchoolCode],
        ['DSO name', student.dsoName],
        ['DSO email', student.dsoEmail],
        ['DSO phone', student.dsoPhone],
        ['Student SEVIS ID', student.sevisId],
        ['STEM OPT requested period', student.optStart && student.optEnd ? `${student.optStart} to ${student.optEnd}` : ''],
        ['Qualifying major and CIP code', student.majorCip],
        ['Degree level', student.degreeLevel],
        ['Degree award date', student.degreeAwardedOn],
        ['Based on a prior degree', student.priorDegree],
        ['Employment authorization number', student.ead],
    ];

    function presentation(key: string): 'form' | 'summary' | 'later' {
        if (editing === key) {
            return 'form';
        }

        const step = workflow.steps.find((item) => item.key === key);

        if (step?.state === 'current') {
            return 'form';
        }

        if (step?.state === 'complete') {
            return 'summary';
        }

        return 'later';
    }

    return (
        <HrmsLayout workspace={workspace}>
            <PageIntro title={record.reference} subtitle={`${record.candidateName} · ${record.candidateEmail} · ${workflow.label}`} />
            {notice && <p className="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</p>}
            <div className="mb-4"><Link className="text-sm font-medium text-blue-700" href={route('hrms.i983')}>Back to I-983 cases</Link></div>
            <ol className="mb-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-6">
                {workflow.steps.map((step, index) => (
                    <li key={step.key} className={`rounded-xl border px-3 py-2 text-sm ${step.state === 'current' ? 'border-blue-700 bg-blue-50 text-blue-950' : step.state === 'complete' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-500'}`}>
                        <p className="text-[11px] font-semibold tracking-wide uppercase">{index + 1}. {step.state === 'current' ? 'Now' : step.state === 'complete' ? 'Done' : 'Later'}</p>
                        <p className="font-medium">{step.label}</p>
                    </li>
                ))}
            </ol>
            {canPreviewDraft && record.status !== 'finalized' && (
                <p className="mb-4 text-sm text-slate-600">
                    <a className="text-blue-700" href={route('hrms.i983.draft', record.id)} target="_blank" rel="noreferrer">Preview draft</a>
                    {' '}— DRAFT – NOT FOR SUBMISSION. This preview is not stored and does not apply the employer signature.
                </p>
            )}
            <div className="grid gap-4">
                <SectionCard title="1. Create Case">
                    <p className="text-sm text-slate-700">{record.candidateName} · {record.candidateEmail}</p>
                    <p className="mt-1 text-sm text-slate-500">This case is open. The student link is in the next step.</p>
                </SectionCard>
                {presentation('student_submission') === 'later' && <SectionCard title="2. Student Submission"><p className="text-sm text-slate-600">This step opens after Create Case.</p></SectionCard>}
                {presentation('student_submission') === 'summary' && (
                    <SectionCard title="2. Student Submission">
                        <p className="text-sm text-slate-700">Submitted {record.submittedAt || '—'}{studentCertification.latest ? ` · ${studentCertification.latest.printedName}` : ''}.</p>
                        <p className="mt-2 text-sm text-slate-600">{link.revoked ? 'The latest link is revoked.' : link.active ? `The link is active until ${link.expiresAt}.` : 'The latest link is expired.'}</p>
                        <button className="mt-3 text-sm font-medium text-blue-700" type="button" onClick={() => setEditing('student_submission')}>Review or change the link</button>
                    </SectionCard>
                )}
                {presentation('student_submission') === 'form' && (
                <div className="grid gap-4">
                <SectionCard title="2. Student Submission">
                    <p className="text-sm text-slate-600">{link.revoked ? 'The latest link is revoked.' : link.active ? `Active until ${link.expiresAt}.` : 'The latest link is expired.'} The link is not emailed. After submission, Section 1 and Student Role stay locked unless you request a correction. Goals stay shared. Employer fields stay on this page.</p>
                    {linkUrl && <p className="mt-3 break-all rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-800">{linkUrl}</p>}
                    <div className="mt-3 flex flex-wrap gap-3">
                        <Link className="rounded-xl border border-slate-200 px-4 py-2 text-sm" href={route('hrms.i983.link.reissue', record.id)} method="post" as="button">Issue a new link</Link>
                        <Link className="rounded-xl border border-slate-200 px-4 py-2 text-sm" href={route('hrms.i983.link.revoke', record.id)} method="post" as="button">Revoke link</Link>
                    </div>
                </SectionCard>
                <SectionCard title="Section 1 review">
                    {record.submittedAt ? <p className="mb-3 text-sm text-slate-600">Submitted {record.submittedAt}. These values are the student's submitted record.</p> : <p className="mb-3 text-sm text-slate-600">The student has not submitted Section 1 yet. A saved draft appears here.</p>}
                    <dl className="grid gap-2 md:grid-cols-2">
                        {facts.map(([labelText, value]) => (
                            <div key={labelText}>
                                <dt className="text-xs uppercase tracking-wide text-slate-500">{labelText}</dt>
                                <dd className="text-sm text-slate-900">{value || '—'}</dd>
                            </div>
                        ))}
                    </dl>
                </SectionCard>
                {record.submittedAt && (
                    <form onSubmit={requestCorrection}>
                        <SectionCard title="Request a student correction">
                            <p className="mb-3 text-sm text-slate-600">This unlocks Section 1 and Student Role for the student. It does not rewrite earlier answers or replace a stored PDF.</p>
                            {correction && <p className="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">Open request from {correction.requestedAt}: {correction.reason}</p>}
                            <label className="text-sm text-slate-700">Reason<textarea className={control} rows={3} value={correctionForm.data.reason} onChange={(event) => correctionForm.setData('reason', event.target.value)} /></label>
                            {correctionForm.errors.reason && <p className="mt-2 text-sm text-rose-700">{correctionForm.errors.reason}</p>}
                            <button className="mt-3 rounded-xl border border-slate-200 px-4 py-2 text-sm" type="submit" disabled={correctionForm.processing}>Request correction</button>
                        </SectionCard>
                    </form>
                )}
                </div>
                )}
                {presentation('employer_information') === 'later' && <SectionCard title="3. Employer Information"><p className="text-sm text-slate-600">This step opens after the student submits Section 1 and Section 2.</p></SectionCard>}
                {presentation('employer_information') === 'summary' && (
                    <SectionCard title="3. Employer Information">
                        <p className="text-sm text-slate-700">{employer.legalName || '—'} · {employer.city || '—'}, {employer.state || '—'} {employer.zip || ''}</p>
                        <p className="mt-1 text-sm text-slate-600">EIN {employer.ein || '—'} · {employer.optHours || '—'} hours · starts {employer.startDate || '—'}</p>
                        <button className="mt-3 text-sm font-medium text-blue-700" type="button" onClick={() => setEditing('employer_information')}>Edit employer information</button>
                    </SectionCard>
                )}
                {presentation('employer_information') === 'form' && (
                <form onSubmit={saveEmployer}>
                    <SectionCard title="3. Employer Information">
                        <p className="mb-3 text-sm text-slate-600">Legal name, address, website, EIN, U.S. employee count, and NAICS are copied from U.S. Employer Information in Company Settings when this case is created. Saving here changes only this I-983.</p>
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm text-slate-700">Legal employer name<input className={control} value={employerForm.data.legal_name} onChange={(event) => employerForm.setData('legal_name', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Website<input className={control} value={employerForm.data.website} onChange={(event) => employerForm.setData('website', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Street address<input className={control} value={employerForm.data.street} onChange={(event) => employerForm.setData('street', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Suite<input className={control} value={employerForm.data.suite} onChange={(event) => employerForm.setData('suite', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">City<input className={control} value={employerForm.data.city} onChange={(event) => employerForm.setData('city', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">State<input className={control} value={employerForm.data.state} onChange={(event) => employerForm.setData('state', event.target.value)} maxLength={2} /></label>
                            <label className="text-sm text-slate-700">ZIP<input className={control} value={employerForm.data.zip} onChange={(event) => employerForm.setData('zip', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">EIN<input className={control} value={employerForm.data.ein} onChange={(event) => employerForm.setData('ein', event.target.value)} autoComplete="off" /></label>
                            <label className="text-sm text-slate-700">Full-time employees in the U.S.<input className={control} value={employerForm.data.employee_count} onChange={(event) => employerForm.setData('employee_count', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">NAICS code<input className={control} value={employerForm.data.naics} onChange={(event) => employerForm.setData('naics', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">OPT hours per week<input className={control} value={employerForm.data.opt_hours} onChange={(event) => employerForm.setData('opt_hours', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Hourly rate in U.S. dollars<input className={control} value={employerForm.data.hourly_rate} onChange={(event) => employerForm.setData('hourly_rate', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Pay frequency
                                <select className={control} value={employerForm.data.salary_frequency} onChange={(event) => employerForm.setData('salary_frequency', event.target.value)}>
                                    <option value="year">Per year</option>
                                    <option value="month">Per month</option>
                                    <option value="hour">Per hour</option>
                                </select>
                            </label>
                            <label className="text-sm text-slate-700">Annual salary in U.S. dollars<input className={control} value={employerForm.data.annual_compensation} onChange={(event) => employerForm.setData('annual_compensation', event.target.value)} /><span className="mt-1 block text-xs text-slate-500">This is the amount printed in Section 3. An hourly rate is stored separately and is not converted into an annual salary.</span></label>
                            <label className="text-sm text-slate-700">Employment start date<input className={control} type="date" value={employerForm.data.start_date} onChange={(event) => employerForm.setData('start_date', event.target.value)} /></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Other compensation<textarea className={control} rows={2} value={employerForm.data.other_compensation} onChange={(event) => employerForm.setData('other_compensation', event.target.value)} /></label>
                        </div>
                        {employerForm.errors.employer && <p className="mt-2 text-sm text-rose-700">{employerForm.errors.employer}</p>}
                        {employerForm.errors.opt_hours && <p className="mt-2 text-sm text-rose-700">{employerForm.errors.opt_hours}</p>}
                        {employerForm.errors.hourly_rate && <p className="mt-2 text-sm text-rose-700">{employerForm.errors.hourly_rate}</p>}
                        {employerForm.errors.annual_compensation && <p className="mt-2 text-sm text-rose-700">{employerForm.errors.annual_compensation}</p>}
                        {employerForm.errors.salary && <p className="mt-2 text-sm text-rose-700">{employerForm.errors.salary}</p>}
                        {employerForm.errors.state && <p className="mt-2 text-sm text-rose-700">{employerForm.errors.state}</p>}
                        <button className="mt-3 rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={employerForm.processing}>Save employer information</button>
                    </SectionCard>
                </form>
                )}
                {presentation('training_plan') === 'later' && <SectionCard title="4. Training Plan"><p className="text-sm text-slate-600">This step opens after Employer Information is saved.</p></SectionCard>}
                {presentation('training_plan') === 'summary' && (
                    <SectionCard title="4. Training Plan">
                        <p className="text-sm text-slate-700">{training.siteName || '—'} · {training.workArrangement || 'work arrangement not selected'}</p>
                        <p className="mt-1 text-sm text-slate-600">{training.officialName || '—'}, {training.officialTitle || '—'}</p>
                        <button className="mt-3 text-sm font-medium text-blue-700" type="button" onClick={() => setEditing('training_plan')}>Edit training plan</button>
                    </SectionCard>
                )}
                {presentation('training_plan') === 'form' && (
                <>
                <form onSubmit={saveTraining}>
                    <SectionCard title="4. Training Plan">
                        <p className="mb-3 text-sm text-slate-600">Student Name comes from Section 1. Employer Name comes from Section 3. Employer HR and the student can both complete Student Role. Goals and Objectives are shared. The training site is saved separately from the employer&apos;s registered address. Site, official, oversight, measures, and remarks are employer-owned.</p>
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm text-slate-700">Student name · student-owned, from Section 1<input className={lockedControl} readOnly value={trainingForm.data.student_name} /></label>
                            <label className="text-sm text-slate-700">Employer name · from Section 3<input className={lockedControl} readOnly value={training.employerName} /></label>
                            <label className="text-sm text-slate-700">Employer site name · employer-owned<input className={control} value={trainingForm.data.site_name} onChange={(event) => trainingForm.setData('site_name', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Site address · street, city, state, ZIP<input className={control} value={trainingForm.data.site_address} onChange={(event) => trainingForm.setData('site_address', event.target.value)} /><span className="mt-1 block text-xs text-slate-500">Street, city, state, and ZIP of the training site. Do not copy the registered company address unless the student trains there.</span></label>
                            <label className="text-sm text-slate-700">Work arrangement
                                <select className={control} value={trainingForm.data.work_arrangement} onChange={(event) => trainingForm.setData('work_arrangement', event.target.value)}>
                                    <option value="">Select remote, on-site, or hybrid</option>
                                    <option value="remote">Remote</option>
                                    <option value="on_site">On-site</option>
                                    <option value="hybrid">Hybrid</option>
                                </select>
                            </label>
                            <label className="text-sm text-slate-700">Official name · employer-owned<input className={control} value={trainingForm.data.official_name} onChange={(event) => trainingForm.setData('official_name', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Official title · employer-owned<input className={control} value={trainingForm.data.official_title} onChange={(event) => trainingForm.setData('official_title', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Official email · employer-owned<input className={control} value={trainingForm.data.official_email} onChange={(event) => trainingForm.setData('official_email', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Official phone · employer-owned<input className={control} value={trainingForm.data.official_phone} onChange={(event) => trainingForm.setData('official_phone', event.target.value)} /></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Student role<textarea className={control} rows={5} value={trainingForm.data.student_role} onChange={(event) => trainingForm.setData('student_role', event.target.value)} /><span className="mt-1 block text-xs text-slate-500">Describe the student&apos;s position and responsibilities, how the position relates to the qualifying STEM degree, and the technical knowledge and skills the student will apply and develop. Changes are kept in the training-plan history.</span></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Goals and objectives · shared<textarea className={control} rows={3} value={trainingForm.data.goals} onChange={(event) => trainingForm.setData('goals', event.target.value)} /></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Employer oversight · employer-owned<textarea className={control} rows={3} value={trainingForm.data.oversight} onChange={(event) => trainingForm.setData('oversight', event.target.value)} /></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Measures and assessments · employer-owned<textarea className={control} rows={3} value={trainingForm.data.measures} onChange={(event) => trainingForm.setData('measures', event.target.value)} /></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Additional remarks · employer-owned<textarea className={control} rows={2} value={trainingForm.data.remarks} onChange={(event) => trainingForm.setData('remarks', event.target.value)} /></label>
                        </div>
                        {trainingForm.errors.official_email && <p className="mt-2 text-sm text-rose-700">{trainingForm.errors.official_email}</p>}
                        {trainingForm.errors.student_role && <p className="mt-2 text-sm text-rose-700">{trainingForm.errors.student_role}</p>}
                        {trainingForm.errors.student_name && <p className="mt-2 text-sm text-rose-700">{trainingForm.errors.student_name}</p>}
                        {trainingForm.errors.site_address && <p className="mt-2 text-sm text-rose-700">{trainingForm.errors.site_address}</p>}
                        {trainingForm.errors.work_arrangement && <p className="mt-2 text-sm text-rose-700">{trainingForm.errors.work_arrangement}</p>}
                        <button className="mt-3 rounded-xl bg-blue-700 px-4 py-2 text-sm font-medium text-white" type="submit" disabled={trainingForm.processing}>Save training plan</button>
                    </SectionCard>
                </form>
                {revisions.length > 0 && (
                    <SectionCard title="Training plan changes">
                        <ul className="space-y-3 text-sm text-slate-700">
                            {revisions.map((revision, index) => (
                                <li key={`${revision.field}-${revision.at}-${index}`}>
                                    <p>{revision.at} · {revision.editor} ({revision.actor}) changed {revision.field.replaceAll('_', ' ')}</p>
                                    <p className="text-slate-500">Previous: {revision.previous || '—'}</p>
                                    <p>New: {revision.next || '—'}</p>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}
                </>
                )}
                {presentation('employer_certification') === 'later' && <SectionCard title="5. Employer Certification"><p className="text-sm text-slate-600">This step opens after the Training Plan is saved.</p></SectionCard>}
                {presentation('employer_certification') === 'summary' && (
                    <SectionCard title="5. Employer Certification">
                        <p className="text-sm text-slate-700">{signatory.name || '—'}, {signatory.title || '—'}. The signature image is saved for this company and is applied when the final PDF is generated.</p>
                        <button className="mt-3 text-sm font-medium text-blue-700" type="button" onClick={() => setEditing('employer_certification')}>Replace the saved signature</button>
                    </SectionCard>
                )}
                {presentation('employer_certification') === 'form' && (
                <form onSubmit={saveSignature}>
                    <SectionCard title="5. Employer Certification">
                        <p className="mb-3 text-sm text-slate-600">Upload the employer official&apos;s signature, name, and title. Saving this image does not sign an I-983. The image stays private to this company.</p>
                        {signatory.hasImage && <img alt="Uploaded employer signature" className="mb-3 h-16 rounded-lg border border-slate-200 bg-white p-2" src={route('hrms.i983.signature.show')} />}
                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="text-sm text-slate-700">Signatory full name<input className={control} value={signatureForm.data.signatory_name} onChange={(event) => signatureForm.setData('signatory_name', event.target.value)} /></label>
                            <label className="text-sm text-slate-700">Official title<input className={control} value={signatureForm.data.signatory_title} onChange={(event) => signatureForm.setData('signatory_title', event.target.value)} /></label>
                            <label className="text-sm text-slate-700 md:col-span-2">Signature image (PNG or JPEG)<input className={control} type="file" accept="image/png,image/jpeg" onChange={(event) => signatureForm.setData('signature', event.target.files?.[0] ?? null)} /></label>
                        </div>
                        {signatureForm.errors.signatory_name && <p className="mt-2 text-sm text-rose-700">{signatureForm.errors.signatory_name}</p>}
                        {signatureForm.errors.signatory_title && <p className="mt-2 text-sm text-rose-700">{signatureForm.errors.signatory_title}</p>}
                        {signatureForm.errors.signature && <p className="mt-2 text-sm text-rose-700">{signatureForm.errors.signature}</p>}
                        <button className="mt-3 rounded-xl border border-slate-200 px-4 py-2 text-sm" type="submit" disabled={signatureForm.processing}>Save signature</button>
                    </SectionCard>
                </form>
                )}
                {presentation('final_pdf') === 'later' && <SectionCard title="6. Generate Final PDF"><p className="text-sm text-slate-600">One Generate Final PDF action stores the HR working copy, the employer-signed copy, and the final file after the earlier steps are complete.</p></SectionCard>}
                {presentation('final_pdf') !== 'later' && (
                <SectionCard title="6. Generate Final PDF">
                    <p className="mb-3 text-sm text-slate-600">Each generation stores a new private copy for this company. Preview and download use the stored file. Nothing is emailed.</p>
                    <p className="mb-3 text-sm text-slate-600">{studentCertification.latest ? `Student Section 2 certification version ${studentCertification.latest.version}: ${studentCertification.latest.printedName}, ${studentCertification.latest.certifiedOn} (${studentCertification.latest.timezone}).${studentCertification.history.length > 1 ? ' Earlier certifications remain stored.' : ''}` : 'Section 2 stays blank until the student certifies it. HR cannot sign Section 2 for the student.'}</p>
                    {canSign && presentation('final_pdf') === 'form' && record.status !== 'finalized' && (
                        <form className="mb-4" onSubmit={generateFinal}>
                            <label className="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" checked={finalForm.data.confirm} onChange={(event) => finalForm.setData('confirm', event.target.checked)} /><span>I confirm this official is authorized to sign, and Section 1 through Section 6 are complete.</span></label>
                            {finalForm.errors.confirm && <p className="mt-2 text-sm text-rose-700">{finalForm.errors.confirm}</p>}
                            {finalForm.errors.form && <p className="mt-2 text-sm text-rose-700">{finalForm.errors.form}</p>}
                            {workflow.blockers.length > 0 && <ul className="mt-2 space-y-1 text-sm text-slate-600">{workflow.blockers.slice(0, 4).map((item) => <li key={item}>{item}</li>)}</ul>}
                            <button className="mt-3 w-full rounded-xl bg-teal-800 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" type="submit" disabled={finalForm.processing || !finalForm.data.confirm || !workflow.canGenerateFinal}>Generate Final PDF</button>
                        </form>
                    )}
                    {record.status === 'finalized' && <p className="mb-4 text-sm text-slate-600">The final PDF is stored below. It is not replaced.</p>}
                    {documents.length === 0 ? <p className="text-sm text-slate-600">No official PDF has been stored.</p> : (
                        <ul className="space-y-3 text-sm text-slate-700">
                            {documents.map((document) => (
                                <li key={document.id} className="rounded-xl border border-slate-200 px-3 py-2">
                                    <p>{documentLabels[document.kind] ?? document.kind} version {document.version}</p>
                                    <p className="text-slate-500">{document.createdAt} · Created by {document.createdBy}</p>
                                    <p className="break-all font-mono text-xs text-slate-600">SHA-256 {document.sha256}</p>
                                    <p className="mt-2 flex gap-3">
                                        <a className="text-blue-700" href={route('hrms.i983.documents.preview', [record.id, document.id])} target="_blank" rel="noreferrer">Preview</a>
                                        <a className="text-blue-700" href={route('hrms.i983.documents.download', [record.id, document.id])}>Download</a>
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
                )}
            </div>
        </HrmsLayout>
    );
}
