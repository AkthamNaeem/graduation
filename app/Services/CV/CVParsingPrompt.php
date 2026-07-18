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
9. For birth_date:
   - When day, month, and year are explicitly available, return YYYY-MM-DD.
   - If a complete birth date is unavailable, return null.
   - Never return a partial birth date.
10. For experience dates:
   - Return YYYY-MM when month and year are available.
   - Return YYYY when only the year is available.
11. Use null as end_date and is_current=true for current positions.
12. Include short evidence copied from the CV for each extracted experience and education item.
13. Do not treat generic prose as a skill unless it is explicitly present in a skills section or clearly used as a technology.
14. Return data only through the supplied JSON schema.
PROMPT;
    }

    public function jsonObjectFallbackText(): string
    {
        return <<<'PROMPT'
Return one valid JSON object only.
Return exactly these top-level keys:
full_name, email, phone, location, birth_date, summary, experience, education, skills, languages.

Use null for unavailable nullable scalar values.
Use empty arrays when no experience, education, skills, or languages exist.
Do not add keys outside the requested contract.
Every experience and education item must include all contract fields.

Experience fields: title, company_name, location, work_mode, start_date, end_date, is_current, description, responsibilities, evidence, confidence_score.
Education fields: degree, field_of_study, institution, start_year, graduation_year, is_expected, description, evidence, confidence_score.
Language fields: name, level.

An empty result is:
{"full_name":null,"email":null,"phone":null,"location":null,"birth_date":null,"summary":null,"experience":[],"education":[],"skills":[],"languages":[]}
PROMPT;
    }
}
