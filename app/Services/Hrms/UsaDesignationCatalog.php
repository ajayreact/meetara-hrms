<?php

namespace App\Services\Hrms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UsaDesignationCatalog
{
    public const TEMPLATE_VERSION = 'usa-profile-1';

    /**
     * @var array<string, string>
     */
    public const GROUPS = [
        'software' => 'Software Development',
        'data' => 'Data & Analytics',
        'ai' => 'Artificial Intelligence & Machine Learning',
        'cloud' => 'Cloud & DevOps',
        'network' => 'Networking & Infrastructure',
        'security' => 'Cybersecurity',
        'enterprise' => 'Enterprise & Platforms',
        'quality' => 'Quality & Project Management',
    ];

    public function manages(object $user): bool
    {
        $role = method_exists($user, 'applicationRole') ? $user->applicationRole() : '';

        return in_array($role, ['company_admin', 'hr'], true);
    }

    public function ensure(int $companyId): void
    {
        if ($companyId <= 0 || ! Schema::hasTable('usa_offer_designations')) {
            return;
        }

        $existing = DB::table('usa_offer_designations')->where('tenant_id', $companyId)->pluck('designation_key')->all();
        $now = now();
        $rows = [];

        foreach (self::defaults() as $index => $designation) {
            if (in_array($designation['key'], $existing, true)) {
                continue;
            }

            $encoded = json_encode($designation['responsibilities'], JSON_THROW_ON_ERROR);
            $rows[] = [
                'tenant_id' => $companyId,
                'designation_key' => $designation['key'],
                'name' => $designation['name'],
                'group_key' => $designation['group'],
                'responsibilities' => $encoded,
                'factory_responsibilities' => $encoded,
                'is_active' => true,
                'is_builtin' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('usa_offer_designations')->insert($rows);
        }
    }

    /**
     * @return list<array{id: int, key: string, name: string, group: string, groupKey: string, active: bool, builtin: bool, used: bool, count: int, responsibilities: list<string>}>
     */
    public function all(int $companyId): array
    {
        $this->ensure($companyId);
        $used = Schema::hasTable('usa_offers')
            ? DB::table('usa_offers')->where('tenant_id', $companyId)->whereNotNull('designation_id')->pluck('designation_id')->all()
            : [];

        return DB::table('usa_offer_designations')
            ->where('tenant_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (object $row) use ($used): array {
                $items = $this->decode($row->responsibilities);

                return [
                    'id' => (int) $row->id,
                    'key' => (string) $row->designation_key,
                    'name' => (string) $row->name,
                    'group' => self::GROUPS[$row->group_key] ?? (string) $row->group_key,
                    'groupKey' => (string) $row->group_key,
                    'active' => (bool) $row->is_active,
                    'builtin' => (bool) $row->is_builtin,
                    'used' => in_array($row->id, $used, false),
                    'count' => count($items),
                    'responsibilities' => $items,
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $responsibilities
     */
    public function save(int $companyId, int $id, array $responsibilities, bool $active): void
    {
        $row = $this->row($companyId, $id);
        $items = $this->clean($responsibilities);

        if ($items === []) {
            throw ValidationException::withMessages(['responsibilities' => 'Enter at least one responsibility.']);
        }

        DB::table('usa_offer_designations')->where('id', $row->id)->update([
            'responsibilities' => json_encode($items, JSON_THROW_ON_ERROR),
            'is_active' => $active,
            'updated_at' => now(),
        ]);
    }

    public function restore(int $companyId, int $id): void
    {
        $row = $this->row($companyId, $id);
        $factory = $this->decode($row->factory_responsibilities);

        if ($row->is_builtin) {
            foreach (self::defaults() as $designation) {
                if ($designation['key'] === $row->designation_key) {
                    $factory = $designation['responsibilities'];
                    break;
                }
            }
        }

        DB::table('usa_offer_designations')->where('id', $row->id)->update([
            'responsibilities' => json_encode($factory, JSON_THROW_ON_ERROR),
            'factory_responsibilities' => json_encode($factory, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $responsibilities
     */
    public function add(int $companyId, string $name, string $group, array $responsibilities): int
    {
        $this->ensure($companyId);
        $name = trim($name);
        $items = $this->clean($responsibilities);

        if ($name === '' || ! isset(self::GROUPS[$group]) || $items === []) {
            throw ValidationException::withMessages(['name' => 'Enter a designation name, group, and at least one responsibility.']);
        }

        $key = Str::slug($name, '_');
        $key = $key !== '' ? $key : 'designation';
        $exists = DB::table('usa_offer_designations')
            ->where('tenant_id', $companyId)
            ->where(function ($query) use ($name, $key): void {
                $query->where('name', $name)->orWhere('designation_key', $key);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'That designation already exists.']);
        }

        $encoded = json_encode($items, JSON_THROW_ON_ERROR);
        $sort = (int) DB::table('usa_offer_designations')->where('tenant_id', $companyId)->max('sort_order');

        return (int) DB::table('usa_offer_designations')->insertGetId([
            'tenant_id' => $companyId,
            'designation_key' => $key,
            'name' => $name,
            'group_key' => $group,
            'responsibilities' => $encoded,
            'factory_responsibilities' => $encoded,
            'is_active' => true,
            'is_builtin' => false,
            'sort_order' => $sort + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function active(int $companyId, int $id): object
    {
        $row = $this->row($companyId, $id);

        if (! $row->is_active) {
            throw ValidationException::withMessages(['designation_id' => 'Choose an active designation.']);
        }

        return $row;
    }

    /**
     * @return list<array{key: string, name: string, group: string, responsibilities: list<string>}>
     */
    public static function defaults(): array
    {
        $items = [];

        foreach (self::source() as $group => $rows) {
            foreach ($rows as $name => $responsibilities) {
                $items[] = [
                    'key' => Str::slug($name, '_'),
                    'name' => $name,
                    'group' => $group,
                    'responsibilities' => $responsibilities,
                ];
            }
        }

        return $items;
    }

    private function row(int $companyId, int $id): object
    {
        $this->ensure($companyId);
        $row = DB::table('usa_offer_designations')->where('tenant_id', $companyId)->where('id', $id)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['designation' => 'That designation is not available.']);
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $this->clean($decoded) : [];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private function clean(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            $line = trim(preg_replace('/\s+/', ' ', (string) $item) ?? '');

            if ($line !== '') {
                $clean[] = mb_substr($line, 0, 500);
            }
        }

        return array_slice($clean, 0, 40);
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private static function source(): array
    {
        return [
            'software' => [
                'Software Engineer' => [
                    'Design, develop, test and maintain scalable software applications.',
                    'Develop backend applications, REST APIs and server-side components.',
                    'Build responsive frontend interfaces.',
                    'Design and optimize database structures and queries.',
                    'Troubleshoot software defects and performance issues.',
                    'Integrate third-party APIs and external systems.',
                    'Perform testing, debugging and code reviews.',
                    'Collaborate with development teams.',
                    'Maintain reusable, documented and maintainable code.',
                    'Participate in technical improvements.',
                ],
                'Software Developer' => [
                    'Develop and maintain software applications.',
                    'Write clean and maintainable source code.',
                    'Implement application features based on requirements.',
                    'Debug and resolve software issues.',
                    'Perform unit and functional testing.',
                    'Maintain technical documentation.',
                    'Collaborate with development teams.',
                    'Improve application reliability and performance.',
                ],
                'Full Stack Developer' => [
                    'Develop frontend and backend application components.',
                    'Build responsive user interfaces.',
                    'Develop APIs and server-side services.',
                    'Integrate databases and external services.',
                    'Implement authentication and application security.',
                    'Debug and optimize application performance.',
                    'Conduct code reviews and testing.',
                    'Maintain application documentation.',
                ],
                'Java Developer' => [
                    'Develop Java-based enterprise applications.',
                    'Build backend services and REST APIs.',
                    'Work with Java frameworks and related technologies.',
                    'Integrate relational databases.',
                    'Implement application security and error handling.',
                    'Perform unit testing and debugging.',
                    'Optimize application performance.',
                    'Maintain technical documentation.',
                ],
                'Java Full Stack Developer' => [
                    'Develop Java-based backend applications.',
                    'Build responsive frontend applications.',
                    'Design REST APIs and service integrations.',
                    'Integrate databases and application services.',
                    'Implement authentication and security.',
                    'Perform full-stack testing and debugging.',
                    'Optimize application performance.',
                    'Collaborate with cross-functional teams.',
                ],
                '.NET Full Stack Developer' => [
                    'Develop applications using .NET technologies.',
                    'Build backend APIs and services.',
                    'Develop responsive frontend components.',
                    'Integrate databases and third-party systems.',
                    'Implement authentication and authorization.',
                    'Perform software testing and debugging.',
                    'Optimize application performance.',
                    'Maintain code quality and documentation.',
                ],
                'Web Developer' => [
                    'Design and develop responsive websites.',
                    'Implement frontend interfaces.',
                    'Maintain website functionality and usability.',
                    'Integrate APIs and web services.',
                    'Optimize website speed and performance.',
                    'Ensure cross-browser compatibility.',
                    'Troubleshoot technical issues.',
                    'Maintain website documentation.',
                ],
                'Python Full Stack Developer' => [
                    'Develop Python-based backend applications.',
                    'Build responsive frontend interfaces.',
                    'Develop APIs and integrate databases.',
                    'Implement application authentication and security.',
                    'Write reusable and maintainable Python code.',
                    'Perform testing and debugging.',
                    'Optimize application performance.',
                    'Maintain technical documentation.',
                ],
            ],
            'data' => [
                'Data Scientist Analytics' => [
                    'Analyze structured and unstructured datasets.',
                    'Apply statistical methods and analytical techniques.',
                    'Develop predictive and analytical models.',
                    'Identify trends, patterns and business insights.',
                    'Prepare data for analytical use.',
                    'Validate analytical results.',
                    'Present findings through reports and visualizations.',
                    'Collaborate with stakeholders on data-driven decisions.',
                ],
                'Data Scientist' => [
                    'Collect, clean and analyze datasets.',
                    'Apply statistical and machine learning techniques.',
                    'Develop predictive models.',
                    'Perform exploratory data analysis.',
                    'Evaluate model performance.',
                    'Identify meaningful data patterns.',
                    'Communicate analytical findings.',
                    'Maintain reproducible analytical workflows.',
                ],
                'Data Analyst' => [
                    'Collect, clean and validate business data.',
                    'Analyze datasets to identify trends and patterns.',
                    'Develop reports and dashboards.',
                    'Use SQL and analytical tools for data extraction.',
                    'Monitor business performance indicators.',
                    'Prepare data visualizations.',
                    'Communicate insights to stakeholders.',
                    'Maintain reporting accuracy and documentation.',
                ],
                'Data Engineer' => [
                    'Design and maintain scalable data pipelines.',
                    'Develop ETL and ELT workflows.',
                    'Integrate data from multiple sources.',
                    'Design and maintain data warehouses.',
                    'Optimize database queries and data processing.',
                    'Implement data quality and validation checks.',
                    'Monitor pipeline performance.',
                    'Maintain data architecture documentation.',
                ],
                'Business Analyst' => [
                    'Gather and document business requirements.',
                    'Analyze existing business processes.',
                    'Prepare functional specifications.',
                    'Coordinate with technical and business teams.',
                    'Document workflows and process improvements.',
                    'Support testing and requirement validation.',
                    'Track project requirements.',
                    'Communicate findings to stakeholders.',
                ],
                'Financial Analyst' => [
                    'Analyze financial statements and business data.',
                    'Prepare financial reports and forecasts.',
                    'Monitor budgets and financial performance.',
                    'Perform variance and trend analysis.',
                    'Develop financial models.',
                    'Support budgeting and planning activities.',
                    'Validate financial data.',
                    'Present financial insights to management.',
                ],
                'Python Programming and Statistical Analytics' => [
                    'Develop Python scripts for data processing and analysis.',
                    'Apply statistical methods to business datasets.',
                    'Perform data cleaning and transformation.',
                    'Develop analytical reports and visualizations.',
                    'Use statistical techniques to identify patterns.',
                    'Validate analytical outputs.',
                    'Automate repetitive analytical tasks.',
                    'Document analytical methods and results.',
                ],
                'Masters in Information Systems' => [
                    'Analyze enterprise information systems and business requirements.',
                    'Support information systems planning and implementation.',
                    'Evaluate system workflows and operational processes.',
                    'Assist with data management and reporting.',
                    'Document technical and functional requirements.',
                    'Coordinate with IT and business stakeholders.',
                    'Support system testing and process improvements.',
                    'Maintain information systems documentation.',
                ],
            ],
            'ai' => [
                'AI Engineer' => [
                    'Design and develop AI-powered applications.',
                    'Integrate artificial intelligence models and services.',
                    'Prepare and process data for AI applications.',
                    'Implement model inference and application integrations.',
                    'Evaluate AI output quality and reliability.',
                    'Optimize AI application performance.',
                    'Implement responsible AI and security practices.',
                    'Maintain technical documentation.',
                ],
                'Software Engineer Agentic AI' => [
                    'Design and develop software systems using agentic AI architectures.',
                    'Implement AI agents and multi-step workflows.',
                    'Integrate language models and external tools.',
                    'Develop orchestration and task execution components.',
                    'Implement context and state management.',
                    'Evaluate agent reliability and output quality.',
                    'Apply security controls to agent interactions.',
                    'Monitor and optimize agent workflows.',
                    'Document system architecture and integrations.',
                ],
                'AI/ML Engineer' => [
                    'Develop artificial intelligence and machine learning solutions.',
                    'Prepare and process training datasets.',
                    'Train and evaluate machine learning models.',
                    'Integrate models into software applications.',
                    'Optimize model performance.',
                    'Monitor deployed models.',
                    'Implement model validation and testing.',
                    'Maintain technical documentation.',
                ],
                'AI/ML Developer' => [
                    'Develop AI and machine learning application components.',
                    'Implement model integrations and inference workflows.',
                    'Prepare and transform datasets.',
                    'Build APIs for AI-powered applications.',
                    'Test and debug model integrations.',
                    'Improve model execution performance.',
                    'Collaborate with software engineering teams.',
                    'Document implementation details.',
                ],
                'Machine Learning Engineer' => [
                    'Develop and deploy machine learning models.',
                    'Prepare training and validation datasets.',
                    'Implement model training pipelines.',
                    'Evaluate and tune model performance.',
                    'Build model-serving APIs.',
                    'Monitor model behavior in production.',
                    'Improve model reliability and scalability.',
                    'Maintain ML pipeline documentation.',
                ],
                'Artificial Intelligence' => [
                    'Develop and integrate artificial intelligence solutions.',
                    'Evaluate AI models and technologies.',
                    'Prepare and analyze datasets.',
                    'Implement AI application workflows.',
                    'Test model functionality and output quality.',
                    'Integrate AI services into applications.',
                    'Improve system reliability.',
                    'Document AI implementation processes.',
                ],
            ],
            'cloud' => [
                'DevOps Engineer' => [
                    'Manage application build and deployment pipelines.',
                    'Automate CI/CD workflows.',
                    'Maintain development and production environments.',
                    'Manage containers and infrastructure configurations.',
                    'Monitor application availability and performance.',
                    'Troubleshoot deployment and infrastructure issues.',
                    'Implement security and access controls.',
                    'Maintain infrastructure documentation.',
                ],
                'AWS DevOps Engineer' => [
                    'Manage AWS cloud infrastructure and services.',
                    'Build and maintain CI/CD pipelines.',
                    'Automate infrastructure provisioning.',
                    'Configure cloud networking and security.',
                    'Manage containerized deployments.',
                    'Monitor AWS resources and application performance.',
                    'Implement backup and recovery practices.',
                    'Optimize cloud resource utilization.',
                ],
                'Cloud Engineer' => [
                    'Design and maintain cloud infrastructure.',
                    'Configure cloud computing services.',
                    'Manage cloud networking and storage.',
                    'Implement infrastructure automation.',
                    'Monitor cloud performance and availability.',
                    'Apply cloud security best practices.',
                    'Troubleshoot infrastructure issues.',
                    'Maintain cloud architecture documentation.',
                ],
            ],
            'network' => [
                'Network Engineer' => [
                    'Design and maintain network infrastructure.',
                    'Configure routers, switches and network devices.',
                    'Monitor network connectivity and performance.',
                    'Troubleshoot network outages and connectivity issues.',
                    'Maintain network documentation.',
                    'Implement network security controls.',
                    'Support network upgrades and maintenance.',
                    'Coordinate network infrastructure improvements.',
                ],
                'IT Engineer' => [
                    'Maintain IT infrastructure and systems.',
                    'Support hardware and software environments.',
                    'Troubleshoot technical issues.',
                    'Assist with system installation and configuration.',
                    'Monitor IT service availability.',
                    'Maintain technical documentation.',
                    'Support security and maintenance procedures.',
                    'Coordinate IT operations.',
                ],
            ],
            'security' => [
                'Cybersecurity Analyst' => [
                    'Monitor security alerts and system events.',
                    'Analyze potential security incidents.',
                    'Perform vulnerability assessments.',
                    'Investigate suspicious activity.',
                    'Support incident response activities.',
                    'Maintain security reports and documentation.',
                    'Recommend security improvements.',
                    'Follow organizational security policies.',
                ],
                'Cybersecurity Engineer' => [
                    'Design and implement security controls.',
                    'Configure security tools and technologies.',
                    'Identify and remediate security vulnerabilities.',
                    'Implement endpoint and network protection.',
                    'Support security architecture improvements.',
                    'Monitor security system performance.',
                    'Assist with incident response.',
                    'Maintain security documentation.',
                ],
                'Network Security Engineer' => [
                    'Design and maintain secure network architecture.',
                    'Configure firewalls and network security devices.',
                    'Monitor network traffic and security events.',
                    'Implement access control policies.',
                    'Identify network vulnerabilities.',
                    'Investigate security incidents.',
                    'Maintain secure network configurations.',
                    'Document security procedures.',
                ],
                'Information Security Analyst' => [
                    'Monitor information security systems.',
                    'Assess organizational security risks.',
                    'Review access controls and security policies.',
                    'Support vulnerability assessments.',
                    'Investigate security incidents.',
                    'Prepare security compliance documentation.',
                    'Recommend information security improvements.',
                    'Support security awareness initiatives.',
                ],
            ],
            'enterprise' => [
                'Salesforce Developer' => [
                    'Develop and customize Salesforce applications.',
                    'Configure Salesforce objects and workflows.',
                    'Implement Apex classes and triggers where applicable.',
                    'Develop Lightning components.',
                    'Integrate Salesforce with external applications.',
                    'Maintain data quality and access controls.',
                    'Test and debug Salesforce functionality.',
                    'Document application configurations.',
                ],
                'Information Technology Service Management' => [
                    'Support IT service management processes.',
                    'Manage incident and service request workflows.',
                    'Assist with problem and change management.',
                    'Monitor service-level performance.',
                    'Maintain IT service documentation.',
                    'Coordinate service delivery activities.',
                    'Identify process improvement opportunities.',
                    'Support IT service reporting.',
                ],
            ],
            'quality' => [
                'QA Automation Engineer' => [
                    'Design and develop automated test scripts.',
                    'Create and maintain test automation frameworks.',
                    'Execute functional and regression tests.',
                    'Validate application functionality.',
                    'Identify and report software defects.',
                    'Integrate automated testing into CI/CD pipelines.',
                    'Analyze test results.',
                    'Maintain test documentation.',
                ],
                'Project Manager' => [
                    'Plan and coordinate project activities.',
                    'Define project scope and deliverables.',
                    'Track schedules, milestones and dependencies.',
                    'Coordinate project resources.',
                    'Manage project risks and issues.',
                    'Communicate progress to stakeholders.',
                    'Facilitate project meetings.',
                    'Maintain project documentation.',
                ],
                'Scrum Master' => [
                    'Facilitate Scrum ceremonies.',
                    'Support sprint planning and reviews.',
                    'Remove blockers affecting the development team.',
                    'Promote Agile practices.',
                    'Coordinate communication between stakeholders and team members.',
                    'Track sprint progress.',
                    'Support continuous improvement.',
                    'Maintain Agile process documentation.',
                ],
            ],
        ];
    }
}
