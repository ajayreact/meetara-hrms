import { FormEvent, ReactNode, useEffect, useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { HrmsLayout, PageIntro } from '@/components/hrms/hrms-layout';
import type { HrmsPageProps } from '@/types/hrms';

interface EmployeeSalary {
    basic: string;
    hra: string;
    special: string;
    monthly: string;
    currency: string;
    effectiveFrom: string;
    appliesTo: string;
}

interface EmployeeChoice {
    id: number;
    code: string;
    name: string;
    email: string | null;
    department: string | null;
    designation: string | null;
    manager: string | null;
    joiningDate: string | null;
    employmentType: string | null;
    workLocation: string | null;
    workMode: string | null;
    workingHours: string | null;
    salary?: EmployeeSalary | null;
}

function applicableSalary(employee: EmployeeChoice | null, country: string): EmployeeSalary | null {
    const salary = employee?.salary;

    if (!salary?.monthly) {
        return null;
    }

    return salary.appliesTo === (country === 'india' ? 'india' : 'usa') ? salary : null;
}

function salaryFields(employee: EmployeeChoice | null, country: string) {
    const salary = applicableSalary(employee, country);

    if (!salary) {
        return { monthly_salary: '', hourly_rate: '', basic_salary: '', hra: '', special_allowance: '' };
    }

    if (country === 'india') {
        return {
            monthly_salary: salary.monthly,
            hourly_rate: '',
            basic_salary: salary.basic,
            hra: salary.hra,
            special_allowance: salary.special,
        };
    }

    return {
        monthly_salary: salary.monthly,
        hourly_rate: '',
        basic_salary: '',
        hra: '',
        special_allowance: '',
    };
}

interface ShiftChoice {
    id: number;
    name: string;
    hours: string;
}

interface Position {
    value: string;
    label: string;
}

interface OfferForm {
    id: number;
    employeeId: number;
    country: string;
    positionCode: string;
    designation: string;
    department: string;
    reportingManager: string;
    workLocation: string;
    employmentType: string;
    workMode: string;
    joiningDate: string;
    dateOfIssue: string;
    monthlySalary: string;
    hourlyRate: string;
    basicSalary: string;
    hra: string;
    specialAllowance: string;
    probationMonths: number | null;
    noticeMonths: number;
    workingHours: string;
    salutation: string;
    candidateAddress: string;
    hrName: string;
    locked: boolean;
}

interface Words {
    monthly: string;
    monthlyWords: string;
    annual: string;
    annualWords: string;
}

const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function today(): string {
    const date = new Date();

    return isoDate(date.getFullYear(), date.getMonth() + 1, date.getDate());
}

function isoDate(year: number, month: number, day: number): string {
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function addCalendarDay(iso: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

    if (!match) {
        return '';
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    date.setDate(date.getDate() + 1);

    return isoDate(date.getFullYear(), date.getMonth() + 1, date.getDate());
}

function longDate(iso: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

    if (!match) {
        return '';
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

    return `${String(date.getDate()).padStart(2, '0')} ${months[date.getMonth()]} ${date.getFullYear()}`;
}

function Field({ label, children, error }: { label: string; children: ReactNode; error?: string }) {
    return (
        <label className="block text-sm text-slate-700">
            {label}
            <div className="mt-1">{children}</div>
            {error && <span className="mt-1 block text-rose-700">{error}</span>}
        </label>
    );
}

const control = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950';

export default function CreateOffer({ workspace, employees = [], shifts = [], positions = [], signatories = { india: '', usa: '' }, offer = null, today: issuedOn = today(), defaultJoining = addCalendarDay(issuedOn), indiaTemplateActive = true, notice }: HrmsPageProps & {
    employees?: EmployeeChoice[];
    shifts?: ShiftChoice[];
    positions?: Position[];
    signatories?: { india: string; usa: string };
    offer?: OfferForm | null;
    today?: string;
    defaultJoining?: string;
    indiaTemplateActive?: boolean;
    notice?: string;
}) {
    const [query, setQuery] = useState('');
    const [words, setWords] = useState<Words | null>(null);
    const form = useForm({
        flow: 'appointment',
        country: offer?.country ?? 'india',
        position_code: offer?.positionCode || positions[0]?.value || 'opt_recruiter',
        employee_id: offer ? String(offer.employeeId) : '',
        designation: offer?.designation ?? '',
        department: offer?.department ?? '',
        reporting_manager: offer?.reportingManager ?? '',
        work_location: offer?.workLocation ?? '',
        employment_type: offer?.employmentType || 'full_time',
        work_mode: offer?.workMode ?? '',
        joining_date: offer?.joiningDate || defaultJoining,
        date_of_issue: offer?.dateOfIssue || issuedOn,
        monthly_salary: offer?.monthlySalary ?? '',
        hourly_rate: offer?.hourlyRate ?? '',
        basic_salary: offer?.basicSalary ?? '',
        hra: offer?.hra ?? '',
        special_allowance: offer?.specialAllowance ?? '',
        probation_months: offer?.probationMonths === null || offer?.probationMonths === undefined ? '' : String(offer.probationMonths),
        notice_unit: 'months',
        notice_months: offer ? String(offer.noticeMonths) : '',
        working_hours: offer?.workingHours ?? '',
        salutation: offer?.salutation ?? '',
        candidate_address: offer?.candidateAddress ?? '',
        hr_name: offer?.hrName || signatories.india || '',
        hr_signature: offer?.hrName || signatories.india || '',
    });
    const selected = employees.find((employee) => String(employee.id) === form.data.employee_id) ?? null;
    const matches = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return employees.filter((employee) => needle === '' || `${employee.name} ${employee.code} ${employee.email ?? ''}`.toLowerCase().includes(needle)).slice(0, 8);
    }, [employees, query]);
    const india = form.data.country === 'india';
    const signatory = india ? signatories.india : signatories.usa;
    const dateInvalid = form.data.date_of_issue !== '' && form.data.joining_date !== '' && form.data.joining_date <= form.data.date_of_issue;
    const dateMessage = dateInvalid ? 'Joining date must be later than the date of issue.' : '';
    const salaryRecord = applicableSalary(selected, form.data.country);

    useEffect(() => {
        if (!india || form.data.monthly_salary.trim() === '') {
            setWords(null);

            return;
        }

        const timer = window.setTimeout(() => {
            const url = route('hrms.offer-letters.amount-words', { amount: form.data.monthly_salary });
            fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((response) => response.ok ? response.json() : null)
                .then((payload: Words | null) => setWords(payload))
                .catch(() => setWords(null));
        }, 300);

        return () => window.clearTimeout(timer);
    }, [form.data.monthly_salary, india]);

    function choose(employee: EmployeeChoice) {
        form.setData({
            ...form.data,
            employee_id: String(employee.id),
            designation: employee.designation ?? '',
            department: employee.department ?? '',
            reporting_manager: employee.manager ?? '',
            work_location: employee.workLocation ?? '',
            employment_type: employee.employmentType || 'full_time',
            work_mode: employee.workMode ?? '',
            working_hours: employee.workingHours ?? '',
            hr_name: signatory,
            hr_signature: signatory,
            ...salaryFields(employee, form.data.country),
        });
        setQuery('');
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            hr_name: signatory || data.hr_name,
            hr_signature: signatory || data.hr_signature,
            probation_months: data.probation_months === '' ? null : Number(data.probation_months),
            notice_months: data.notice_months === '' ? null : Number(data.notice_months),
            hourly_rate: india ? null : data.hourly_rate,
        }));
        if (offer) {
            form.post(route('hrms.offer-letters.update', offer.id));

            return;
        }
        form.post(route('hrms.offer-letters.store'));
    }

    const hourlyAnnual = Number(form.data.hourly_rate);

    return (
        <HrmsLayout workspace={workspace} title="Create offer letter">
            <PageIntro title={offer ? 'Edit offer letter' : 'Create offer letter'} subtitle="These details are saved on the offer. The employee record is not changed." actions={<Link className="text-sm text-slate-500 underline" href={route('hrms.offer-letters')}>Back</Link>} />
            {notice && <p className="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">{notice}</p>}
            {!signatory && <p className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">Add the HR signatory in Company offer settings before saving a letter.</p>}
            <form className="grid gap-4" onSubmit={submit}>
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold text-slate-950">Employee information</h2>
                    <div className="mt-4 grid gap-4">
                        <Field label="Search employee">
                            <input className={control} value={query} placeholder="Name, employee ID, or email" onChange={(event) => setQuery(event.target.value)} />
                        </Field>
                        {query.trim() !== '' && (
                            <div className="overflow-hidden rounded-xl border border-slate-200">
                                {matches.length === 0 && <p className="px-3 py-2 text-sm text-slate-500">No matching employee.</p>}
                                {matches.map((employee) => (
                                    <button key={employee.id} className="block w-full border-t border-slate-100 px-3 py-2 text-left text-sm first:border-t-0 hover:bg-slate-50" type="button" onClick={() => choose(employee)}>
                                        <span className="font-medium">{employee.name}</span>
                                        <span className="ml-2 text-slate-500">{employee.code}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {form.errors.employee_id && <p className="text-sm text-rose-700">{form.errors.employee_id}</p>}
                        {selected && (
                            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                {[['Name', selected.name], ['Employee ID', selected.code], ['Email', selected.email || 'Not on the employee record'], ['Department', selected.department || 'Not on the employee record'], ['Designation', selected.designation || 'Not on the employee record'], ['Reporting manager', selected.manager || 'Not on the employee record'], ['Work location', selected.workLocation || 'Not on the employee record']].map(([key, value]) => (
                                    <div key={key}>
                                        <dt className="text-slate-500">{key}</dt>
                                        <dd className="text-slate-950">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                </section>
                <section className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 className="text-base font-semibold text-slate-950">Offer details</h2>
                    {india && <p className="mt-3 text-sm text-slate-950">Standard India Appointment Letter</p>}
                    {india && !indiaTemplateActive && <p className="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">The India Appointment Letter must be approved and activated before a new offer can use it.</p>}
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <Field label="Letter" error={form.errors.country}>
                            <select className={control} value={form.data.country} onChange={(event) => {
                                const country = event.target.value;
                                form.setData({
                                    ...form.data,
                                    country,
                                    ...(!offer && selected ? salaryFields(selected, country) : {}),
                                });
                            }}>
                                <option value="india">India appointment letter</option>
                                <option value="usa">USA offer</option>
                            </select>
                        </Field>
                        {!india && (
                            <Field label="Position" error={form.errors.position_code}>
                                <select className={control} value={form.data.position_code} onChange={(event) => form.setData('position_code', event.target.value)}>
                                    {positions.map((position) => <option key={position.value} value={position.value}>{position.label}</option>)}
                                </select>
                            </Field>
                        )}
                        {india && (
                            <Field label="Salutation" error={form.errors.salutation}>
                                <select className={control} value={form.data.salutation} onChange={(event) => form.setData('salutation', event.target.value)}>
                                    <option value="">None</option>
                                    <option value="mr">Mr.</option>
                                    <option value="ms">Ms.</option>
                                    <option value="mrs">Mrs.</option>
                                    <option value="dr">Dr.</option>
                                </select>
                            </Field>
                        )}
                        <Field label="Designation on this offer" error={form.errors.designation}>
                            <input className={control} value={form.data.designation} onChange={(event) => form.setData('designation', event.target.value)} />
                        </Field>
                        <Field label="Department on this offer" error={form.errors.department}>
                            <input className={control} value={form.data.department} onChange={(event) => form.setData('department', event.target.value)} />
                        </Field>
                        <Field label="Reporting manager on this offer" error={form.errors.reporting_manager}>
                            <input className={control} value={form.data.reporting_manager} onChange={(event) => form.setData('reporting_manager', event.target.value)} />
                        </Field>
                        <Field label="Candidate address" error={form.errors.candidate_address}>
                            <input className={control} value={form.data.candidate_address} onChange={(event) => form.setData('candidate_address', event.target.value)} />
                        </Field>
                        <Field label="Work location on this offer" error={form.errors.work_location}>
                            <input className={control} value={form.data.work_location} onChange={(event) => form.setData('work_location', event.target.value)} />
                        </Field>
                        <Field label="Date of issue" error={form.errors.date_of_issue}>
                            <input className={control} type="date" value={form.data.date_of_issue} onChange={(event) => {
                                const issued = event.target.value;
                                const joining = form.data.joining_date !== '' && form.data.joining_date > issued ? form.data.joining_date : addCalendarDay(issued);
                                form.setData({ ...form.data, date_of_issue: issued, joining_date: joining });
                            }} />
                            <span className="mt-1 block text-slate-950">{longDate(form.data.date_of_issue)}</span>
                        </Field>
                        <Field label="Joining date" error={form.errors.joining_date || dateMessage}>
                            <input className={control} type="date" value={form.data.joining_date} onChange={(event) => form.setData('joining_date', event.target.value)} />
                            <span className="mt-1 block text-slate-950">{longDate(form.data.joining_date)}</span>
                        </Field>
                        <Field label="Employment type">
                            <select className={control} value={form.data.employment_type} onChange={(event) => form.setData('employment_type', event.target.value)}>
                                <option value="full_time">Full time</option>
                                <option value="part_time">Part time</option>
                                <option value="contract">Contract</option>
                            </select>
                        </Field>
                        <Field label="Work mode" error={form.errors.work_mode}>
                            <select className={control} value={form.data.work_mode} onChange={(event) => form.setData('work_mode', event.target.value)}>
                                <option value="">Select</option>
                                <option value="office">Office</option>
                                <option value="hybrid">Hybrid</option>
                                <option value="remote">Remote</option>
                            </select>
                        </Field>
                        {selected && (
                            <div className="sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                {salaryRecord ? (
                                    <>
                                        <p className="font-medium text-slate-950">Salary fetched from Employee Salary Records</p>
                                        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                                            {india ? (
                                                <>
                                                    <div><dt className="text-slate-500">Monthly salary</dt><dd className="text-slate-950">{salaryRecord.monthly}</dd></div>
                                                    <div><dt className="text-slate-500">Basic salary</dt><dd className="text-slate-950">{salaryRecord.basic}</dd></div>
                                                    <div><dt className="text-slate-500">HRA</dt><dd className="text-slate-950">{salaryRecord.hra}</dd></div>
                                                    <div><dt className="text-slate-500">Special allowance</dt><dd className="text-slate-950">{salaryRecord.special}</dd></div>
                                                </>
                                            ) : (
                                                <div><dt className="text-slate-500">Monthly amount</dt><dd className="text-slate-950">{salaryRecord.monthly}</dd></div>
                                            )}
                                            <div><dt className="text-slate-500">Effective from</dt><dd className="text-slate-950">{salaryRecord.effectiveFrom}</dd></div>
                                        </dl>
                                    </>
                                ) : (
                                    <p>No active salary record found. Please enter the proposed offer salary.</p>
                                )}
                            </div>
                        )}
                        {selected && <h2 className="sm:col-span-2 text-base font-semibold text-slate-950">Salary details</h2>}
                        {selected && <p className="sm:col-span-2 text-sm font-medium text-slate-950">Proposed offer salary. Saved on this offer only.</p>}
                        {india ? (
                            <>
                                <Field label="Monthly salary (INR)" error={form.errors.monthly_salary}>
                                    <input className={control} inputMode="decimal" value={form.data.monthly_salary} onChange={(event) => form.setData('monthly_salary', event.target.value)} />
                                </Field>
                                <Field label="Basic salary">
                                    <input className={control} inputMode="decimal" value={form.data.basic_salary} onChange={(event) => form.setData('basic_salary', event.target.value)} />
                                </Field>
                                <Field label="HRA">
                                    <input className={control} inputMode="decimal" value={form.data.hra} onChange={(event) => form.setData('hra', event.target.value)} />
                                </Field>
                                <Field label="Special allowance">
                                    <input className={control} inputMode="decimal" value={form.data.special_allowance} onChange={(event) => form.setData('special_allowance', event.target.value)} />
                                </Field>
                            </>
                        ) : (
                            <>
                                <Field label="Monthly amount" error={form.errors.monthly_salary}>
                                    <input className={control} inputMode="decimal" value={form.data.monthly_salary} onChange={(event) => form.setData('monthly_salary', event.target.value)} />
                                </Field>
                                <Field label="Hourly rate (USD)" error={form.errors.hourly_rate}>
                                    <input className={control} inputMode="decimal" value={form.data.hourly_rate} onChange={(event) => form.setData('hourly_rate', event.target.value)} />
                                </Field>
                            </>
                        )}
                        <Field label="Probation (months)" error={form.errors.probation_months}>
                            <input className={control} inputMode="numeric" value={form.data.probation_months} onChange={(event) => form.setData('probation_months', event.target.value)} />
                        </Field>
                        <Field label="Notice period (months)" error={form.errors.notice_months}>
                            <input className={control} inputMode="numeric" value={form.data.notice_months} onChange={(event) => form.setData('notice_months', event.target.value)} />
                        </Field>
                        <div className="sm:col-span-2">
                            <Field label="Working hours" error={form.errors.working_hours}>
                                {shifts.length > 0 && (
                                    <select className={`${control} mb-2`} value="" onChange={(event) => { if (event.target.value !== '') form.setData('working_hours', event.target.value); }}>
                                        <option value="">Select a saved shift</option>
                                        {shifts.map((shift) => <option key={shift.id} value={shift.hours}>{shift.name}: {shift.hours}</option>)}
                                    </select>
                                )}
                                <input className={control} value={form.data.working_hours} onChange={(event) => form.setData('working_hours', event.target.value)} />
                            </Field>
                            {selected?.workingHours && form.data.working_hours === '' && <p className="mt-2 text-sm text-slate-500">Recorded shift: {selected.workingHours}. It is not filled in until you select it.</p>}
                        </div>
                    </div>
                    {india && words && (
                        <div className="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <p>Annual CTC {words.annual}. Monthly salary × 12.</p>
                            <p className="mt-1">Salary in words: {words.monthlyWords}</p>
                            <p>Annual CTC in words: {words.annualWords}</p>
                        </div>
                    )}
                    {!india && Number.isFinite(hourlyAnnual) && form.data.hourly_rate.trim() !== '' && (
                        <p className="mt-4 text-sm text-slate-700">Annual amount { (hourlyAnnual * 2080).toFixed(2) }. Hourly rate × 2,080.</p>
                    )}
                    {form.errors.letter && <p className="mt-3 text-sm text-rose-700">{form.errors.letter}</p>}
                </section>
                <div className="flex flex-wrap gap-2">
                    <button className="rounded-xl bg-blue-700 px-4 py-2 text-sm text-white disabled:opacity-50" type="submit" disabled={form.processing || !signatory || !selected || dateInvalid}>Save and continue</button>
                </div>
            </form>
        </HrmsLayout>
    );
}
