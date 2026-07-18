<?php

namespace App\Services\CV;

class CVParsingPrompt
{
    public function text(): string
    {
        return <<<'PROMPT'
You extract structured facts from CV text.

Rules:
1. Use only facts explicitly supported by the supplied CV text.
2. Never invent employers, job titles, dates, degrees, institutions, skills, languages, or personal information.
3. Return null or an empty array when information is unavailable.
4. A date range is not a job title or company.
5. "Present", "Current", month names, and years cannot be company names.
6. Bullet-point responsibilities are not separate work experiences.
7. Group adjacent lines belonging to the same experience or education entry.
8. Preserve employer and job-title wording from the CV when possible.
9. Normalize dates to YYYY-MM when the month is available and YYYY when only the year is available.
10. Use null as end_date and is_current=true for current positions.
11. Include short evidence copied from the CV for each extracted experience and education item.
12. Do not treat generic prose as a skill unless it is explicitly present in a skills section or clearly used as a technology.
13. Return data only through the supplied JSON schema.
PROMPT;
    }
}
