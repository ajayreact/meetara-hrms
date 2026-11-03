<?php

namespace App\Services\Hrms;

class IndiaAppointment
{
    /**
     * Structured India appointment letter taken from the supplied eight-page letter.
     * Sample names, dates, salary, and hours are placeholders.
     *
     * @return array{kind: string, blocks: list<array<string, mixed>>}
     */
    public static function document(): array
    {
        return [
            'kind' => 'appointment',
            'blocks' => [
                ['type' => 'title', 'text' => 'APPOINTMENT LETTER', 'bold' => true, 'align' => 'center', 'size' => 16],
                ['type' => 'paragraph', 'text' => 'Registered office: {{company_address}}'],
                ['type' => 'date', 'text' => 'Date of Issue: {{date_of_issue}}'],
                ['type' => 'greeting', 'text' => '{{salutation_line}}'],
                ['type' => 'paragraph', 'text' => 'We are pleased to offer you employment with {{company_legal_name}} as an {{designation}}, working on a {{work_mode}} basis, subject to the terms and conditions outlined in this letter.'],
                ['type' => 'paragraph', 'text' => 'Your role will involve the duties assigned to the {{designation}} position, as communicated by your reporting manager.'],
                ['type' => 'paragraph', 'text' => 'Please read the following terms and conditions carefully.'],
                self::clause('1', 'Acceptance and Commencement of Employment', 'Your appointment will be effective from your date of joining, {{joining_date}}. If you require an alternative joining date, please contact the Company immediately.'),
                self::paragraph('You are required to confirm your acceptance of this offer by signing and returning a copy of this letter to {{company_legal_name}}. Failure to confirm your acceptance or agree upon an alternative joining date may result in the withdrawal of this offer.'),
                self::paragraph('You must comply with the official working hours applicable to your role and business requirements.'),
                self::paragraph("Your initial work arrangement will be {{work_mode}}. You are required to maintain a suitable working environment, reliable internet connectivity, and availability during the Company's approved working schedule."),
                self::clause('2', 'Terms and Conditions of Employment', ''),
                self::subclause('2.1', 'Outside Employment', 'You shall not undertake any other employment, operate your own business, or engage in external commercial activities that create a conflict of interest with the Company without prior written approval, subject to applicable law.'),
                self::subclause('2.2', 'Duties and Responsibilities', 'You shall effectively perform all duties and responsibilities assigned by your manager or any other authorized representative of the Company.'),
                self::paragraph('Your performance will be evaluated periodically in accordance with Company policies and the targets communicated by your reporting manager.'),
                self::subclause('2.3', 'Confidentiality', "During your employment and thereafter, you shall not disclose confidential information concerning the Company's business, operations, clients, candidates, customers, or internal affairs to unauthorized third parties, except where disclosure is legally required or permitted in the proper course of your employment."),
                self::paragraph('All information obtained through your employment shall be treated as confidential.'),
                self::clause('3', 'Conflict of Interest', 'You confirm that you have disclosed any business interests that may conflict, or appear to conflict, with the business activities of {{company_legal_name}}.'),
                self::paragraph('You agree to disclose promptly any such interests or circumstances that arise during your employment.'),
                self::clause('4', 'Non-Solicitation and Protection of Business Interests', "During your employment, you shall not improperly divert the Company's business, misuse confidential information, or interfere with its legitimate business relationships."),
                self::paragraph('Any post-employment restrictions relating to the solicitation of clients, candidates, or employees shall apply only to the extent permitted by applicable law.'),
                self::clause('5', 'Conduct, Discipline, and Integrity', 'You are required to maintain high standards of personal conduct, integrity, professionalism, and discipline and comply with all applicable Company policies and procedures.'),
                self::paragraph('Acts such as insubordination, gross negligence, corruption, fraud, forgery, misappropriation, falsification of candidate records, or other serious misconduct may result in disciplinary action, including termination of employment, in accordance with applicable law and Company policy.'),
                self::clause('6', 'Return of Company Property', 'Upon resignation, termination, or separation from the Company for any reason, you shall promptly return all Company assets and property in your possession or custody.'),
                self::paragraph('These include documents, files, books, papers, memoranda, computers, equipment, data, access credentials, and any other Company-owned or assigned property.'),
                self::clause('7', 'Violation of Company Policies', 'Any violation of the terms of this offer letter or other applicable Company policies and procedures may result in appropriate disciplinary action in accordance with Company policy and applicable law.'),
                self::clause('8', 'Unauthorized Absence', 'You must obtain approval for leave and inform your reporting manager promptly if you are unable to attend work.'),
                self::paragraph("Unauthorized absence, repeated attendance violations, or failure to follow approved leave procedures may be addressed under the Company's disciplinary procedures, subject to applicable law."),
                self::clause('9', 'Commitment Period and Probation', 'You will be on probation for a period of {{probation_period}} from your date of joining. During probation, your performance, conduct, recruitment productivity, communication skills, and suitability for the position will be evaluated.'),
                self::paragraph('Upon successful completion of probation, and if found suitable by the Company, you will receive written confirmation of employment. The Company may extend the probation period where reasonably necessary and in accordance with applicable law and Company policy.'),
                self::paragraph('You are expected to maintain a professional commitment to the Company and fulfil the responsibilities assigned to you.'),
                self::paragraph('Your employment will be subject to a written notice period of {{notice_period}} or salary in lieu of notice, where permitted by applicable law and the terms of employment.'),
                self::clause('10', 'Termination of Employment', 'Either party may terminate the employment relationship by providing {{notice_period}} written notice or salary in lieu of notice, subject to the terms of employment and applicable law.'),
                self::paragraph('The Company may terminate your employment in accordance with applicable law and Company policy. Depending on the circumstances, disciplinary action, including termination, may be taken for serious misconduct or other lawful grounds, including:'),
                ['type' => 'roman', 'items' => [
                    'Misconduct, misbehavior, or unsatisfactory performance.',
                    'Breach of written Company policies.',
                    'Breach of any lawful obligation under this offer letter.',
                    'Dishonesty, willful misconduct, or criminal conduct relevant to employment.',
                    'Insubordination.',
                    'Misappropriation of Company money or property, or other serious wrongdoing.',
                    'Deliberate failure to perform assigned duties or comply with lawful and reasonable instructions.',
                    'Repeated failure to meet reasonable monthly recruitment targets after appropriate guidance and performance review.',
                ]],
                self::paragraph('Any notice requirements and termination procedures shall be subject to applicable law and the terms of employment.'),
                self::clause('11', 'Working Hours and Leave Policy', "Your normal working hours will be {{working_hours}} as per the Company's business requirements."),
                self::paragraph('These hours are the hours recorded for this appointment. A schedule may continue after midnight when that is the working arrangement recorded for this offer.'),
                self::paragraph("You will be entitled to leave in accordance with the Company's leave policy, as amended from time to time, subject to applicable Indian employment laws."),
                self::clause('12', 'Documents Required at the Time of Joining', 'You are required to submit the following documents, as applicable:'),
                ['type' => 'bullets', 'items' => [
                    'Updated resume.',
                    'Government-issued identity and address proof.',
                    'Academic and professional certificates.',
                    'Previous employment and experience certificates, where applicable.',
                    'Relieving letter from your previous employer, where applicable.',
                    'Recent salary slips or relevant employment verification documents, where applicable.',
                    'PAN card and bank account details required for payroll.',
                    'Recent photographs, if required.',
                    'Other documents reasonably required for employment verification and onboarding.',
                ]],
                self::paragraph('You must provide documents reasonably required for employment verification and statutory compliance. All mandatory documentation requirements will be handled in accordance with applicable law.'),
                self::clause('13', 'Accuracy of Information Provided', 'This offer is based on the qualifications, professional skills, experience, and other particulars declared by you during the recruitment process.'),
                self::paragraph('If any material information provided by you is subsequently found to be false, misleading, or materially inaccurate, the Company may take appropriate action, including termination of employment, in accordance with applicable law and Company policy.'),
                self::clause('14', 'Change of Designation or Responsibilities', 'Your designation, responsibilities, or assignments may be reasonably changed by the Company based on business requirements, your skills, and the nature of the work assigned to you, subject to applicable law and the terms of your employment.'),
                self::clause('15', 'Return of Assets Upon Separation', 'Upon resignation or termination of your employment, you must return all Company assets and property, including documents, computers, data, files, identification cards, recruitment records, and other materials belonging to the Company.'),
                self::paragraph('You must not retain or use Company confidential information or property after your employment ends, except as authorized by the Company or permitted by law.'),
                self::clause('16', 'Amendment of Terms and Conditions', 'Any amendment to the terms and conditions of your employment shall be communicated to you in writing and implemented in accordance with applicable law and any required employee consent.'),
                self::clause('17', 'Remuneration and Salary', ''),
                ['type' => 'salary', 'lines' => [
                    'Your monthly salary will be {{monthly_salary}} ({{salary_in_words}}).',
                    'Your annualized salary will be {{annual_ctc}} ({{annual_ctc_in_words}}).',
                ], 'notes' => [
                    "Your salary will be processed according to the Company's payroll schedule and applicable statutory requirements. Any applicable statutory deductions will be made in accordance with Indian law.",
                    'Any incentives or performance-based payments will be governed by a separate written compensation structure, if applicable.',
                    'Your personal remuneration details and related employment records will be handled confidentially, subject to applicable law and legitimate disclosure requirements.',
                ]],
                self::clause('18', 'Safekeeping of Company Property', 'You are responsible for the proper care and safekeeping of Company property entrusted to you, including computers, documents, recruitment databases, software accounts, equipment, and other organizational assets.'),
                self::paragraph('All such property must be maintained in good condition and returned when requested or upon separation from the Company.'),
                self::paragraph('You shall not misuse Company property or use it for unlawful or unauthorized personal purposes.'),
                self::clause('19', 'Workplace Conduct and Prohibited Activities', 'You are expected to maintain a professional, respectful, and inclusive workplace and comply with applicable Company policies regarding workplace conduct.'),
                self::paragraph('Possession, consumption, or use of prohibited substances on Company premises or during working hours is not permitted, subject to applicable law and Company policy.'),
                self::paragraph('You must not engage in harassment, discrimination, fraudulent conduct, unauthorized system access, or misuse of Company facilities and resources.'),
                self::clause('20', 'Protection of Company Information and Monthly Recruitment Targets', 'You must not disclose, share, transfer, or misuse confidential Company information, client information, candidate records, business documents, or proprietary data with unauthorized individuals or organizations.'),
                self::paragraph('You must follow all applicable information-security, data-protection, and confidentiality policies.'),
                self::paragraph('As an {{designation}}, you are required to achieve the monthly recruitment targets assigned by {{company_legal_name}}.'),
                self::paragraph('The Company will communicate your monthly targets, performance expectations, and reporting requirements through your reporting manager or an authorized representative.'),
                self::paragraph('You are expected to:'),
                ['type' => 'roman', 'items' => [
                    'Achieve the monthly sourcing, candidate-contacting, screening, and other recruitment targets assigned to you.',
                    "Maintain daily activity reports and accurate candidate records in the Company's approved recruitment systems.",
                    'Follow up with assigned candidates and update their recruitment status regularly.',
                    'Coordinate with the recruitment team and provide timely updates regarding progress, challenges, and pending activities.',
                    'Attend scheduled meetings, training sessions, and performance reviews as required.',
                    'Make consistent efforts to achieve the assigned targets and implement corrective guidance provided by your reporting manager.',
                ]],
                self::paragraph('Your performance will be reviewed periodically based on target achievement, productivity, candidate engagement, data accuracy, attendance, and compliance with Company procedures.'),
                self::paragraph('Repeated failure to meet reasonable performance expectations may result in performance improvement measures or other appropriate action in accordance with applicable law and Company policy. Any unauthorized disclosure or misuse of confidential information may result in disciplinary action and, where appropriate, legal proceedings in accordance with applicable law.'),
                self::clause('21', 'Confidentiality of This Offer', 'The terms of this offer letter and your employment-related information should be treated confidentially, except where disclosure is required by law or is reasonably necessary for professional advice, legal compliance, or the exercise of your employment rights.'),
                self::clause('22', 'Acceptance of Appointment', "Please read this offer letter carefully and initial each page if required by the Company's onboarding procedure."),
                self::paragraph('Kindly sign and return a copy of this offer letter to {{company_legal_name}} on or before your date of joining as confirmation of your acceptance.'),
                self::paragraph('We welcome you to {{company_legal_name}} and wish you every success in your professional career with us.'),
                ['type' => 'closing', 'lines' => [
                    'With warm regards,',
                    'For {{company_legal_name}}',
                    '{{signatory_name}}',
                    '{{signatory_designation}}',
                ]],
                ['type' => 'acceptance', 'heading' => 'EMPLOYEE ACCEPTANCE', 'body' => 'I, {{employee_name}}, confirm that I have read and understood the terms and conditions stated in this appointment letter and accept the appointment as {{designation}} with {{company_legal_name}}.', 'lines' => [
                    'Employee Name: {{employee_name}}',
                    'Signature: __________________________',
                    'Date: _______________________________',
                    'Date of Joining: {{joining_date}}',
                ]],
            ],
        ];
    }

    public static function json(): string
    {
        $json = json_encode(self::document(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }

    public static function html(): string
    {
        $html = '';

        foreach (self::document()['blocks'] as $block) {
            $html .= self::blockHtml($block);
        }

        return $html;
    }

    /**
     * @return list<string>
     */
    public static function clauseHeadings(): array
    {
        $headings = [];

        foreach (self::document()['blocks'] as $block) {
            if (($block['type'] ?? '') === 'clause') {
                $headings[] = (string) $block['heading'];
            }
        }

        return $headings;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function blockHtml(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');

        return match ($type) {
            'title' => '<h1 style="text-align: center; font-family: Times New Roman; font-size: 16pt"><strong>'.self::text((string) ($block['text'] ?? '')).'</strong></h1>',
            'date', 'greeting', 'paragraph' => '<p>'.self::text((string) ($block['text'] ?? '')).'</p>',
            'clause' => self::headingHtml('h2', trim((string) ($block['number'] ?? '').'. '.(string) ($block['heading'] ?? '')), (string) ($block['body'] ?? '')),
            'subclause' => self::headingHtml('h3', trim((string) ($block['marker'] ?? '').' '.(string) ($block['heading'] ?? '')), (string) ($block['body'] ?? '')),
            'bullets' => self::listHtml('ul', $block['items'] ?? [], null),
            'roman' => self::listHtml('ol', $block['items'] ?? [], 'i'),
            'salary' => self::salaryHtml($block),
            'closing' => self::closingHtml($block),
            'acceptance' => self::acceptanceHtml($block),
            'break' => '<div data-page-break="true"></div>',
            default => '',
        };
    }

    private static function headingHtml(string $tag, string $heading, string $body): string
    {
        $html = '<'.$tag.' style="font-size: 12pt"><strong>'.self::text($heading).'</strong></'.$tag.'>';

        if (trim($body) !== '') {
            $html .= '<p>'.self::text($body).'</p>';
        }

        return $html;
    }

    private static function listHtml(string $tag, mixed $items, ?string $type): string
    {
        $rows = '';

        foreach (is_array($items) ? $items : [] as $item) {
            $rows .= '<li><p>'.self::text((string) $item).'</p></li>';
        }

        $attribute = $type === null ? '' : ' type="'.self::text($type).'"';

        return '<'.$tag.$attribute.'>'.$rows.'</'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function salaryHtml(array $block): string
    {
        $html = '';

        foreach (is_array($block['lines'] ?? null) ? $block['lines'] : [] as $line) {
            $html .= '<p><strong>'.self::text((string) $line).'</strong></p>';
        }

        foreach (is_array($block['notes'] ?? null) ? $block['notes'] : [] as $note) {
            $html .= '<p>'.self::text((string) $note).'</p>';
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function closingHtml(array $block): string
    {
        $lines = array_values(is_array($block['lines'] ?? null) ? $block['lines'] : []);
        $html = '';

        foreach ($lines as $index => $line) {
            if ($index === 2) {
                $html .= '<div data-signature="hr"></div>';
            }

            $html .= '<p>'.self::text((string) $line).'</p>';
        }

        if (count($lines) < 3) {
            $html .= '<div data-signature="hr"></div>';
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function acceptanceHtml(array $block): string
    {
        $html = '<h2 style="font-size: 12pt"><strong>'.self::text((string) ($block['heading'] ?? 'EMPLOYEE ACCEPTANCE')).'</strong></h2>';
        $html .= '<p>'.self::text((string) ($block['body'] ?? '')).'</p>';

        foreach (is_array($block['lines'] ?? null) ? $block['lines'] : [] as $line) {
            $html .= '<p>'.self::text((string) $line).'</p>';
        }

        return $html;
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private static function clause(string $number, string $heading, string $body): array
    {
        return ['type' => 'clause', 'number' => $number, 'heading' => $heading, 'body' => $body, 'bold' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function subclause(string $marker, string $heading, string $body): array
    {
        return ['type' => 'subclause', 'marker' => $marker, 'heading' => $heading, 'body' => $body, 'bold' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'text' => $text];
    }
}
