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
14. Extract every distinct experience entry found in the CV.
15. Freelance, self-employed, contract, internship, part-time, temporary, consulting, and volunteer roles are valid experience entries.
16. Concurrent or overlapping jobs are valid and must remain separate entries. Do not omit an experience because its dates overlap another experience.
17. Freelance is a valid company_name. "Freelance" and "Self-employed" are valid company_name values.
18. Do not merge separate employers or roles into one experience. Preserve every responsibility bullet belonging to its experience.
19. Extract every education entry explicitly present in the CV. A degree, field of study, institution, and date range on adjacent lines form one entry. "Expected" graduation means is_expected=true.
20. description is only an independent general description explicitly present in the CV; otherwise return null. Keep every responsibility bullet in responsibilities and never copy the first responsibility into description.
21. Return one skill per array item. Do not group comma-separated skills. Keep a parenthetical specialization with its parent skill.
22. Extract every explicitly listed certification, certificate, professional course, license, or formal training entry. Certification names and years may appear on adjacent lines.
23. Recognize section headings such as CERTIFICATIONS, CERTIFICATES, LICENSES, COURSES, TRAINING, and PROFESSIONAL TRAINING, including minor spelling mistakes when the section context is clear.
24. Do not classify education as certification. Do not classify a university degree as a certification.
25. Do not classify a normal skill as a certification unless it appears under a certification, certificate, license, course, or training section.
26. Certification issuer and expiration_year must be null when not explicitly present. Return an empty certifications array when none are present.
27. Extract nationality and marital status only when explicitly written. Never infer nationality from location, phone number, language, name, employer, country, or address. Never infer marital status. Return null for missing nationality or marital status.
28. Return data only through the supplied JSON schema.
PROMPT;
    }

    public function jsonObjectText(): string
    {
        return <<<'PROMPT'
You extract structured facts from CV text.

Use only facts explicitly supported by the supplied CV text. Never invent personal information, employers, job titles, dates, education, skills, or languages.
For birth_date, return YYYY-MM-DD only when day, month, and year are explicit; otherwise return null.
For experience dates, return YYYY-MM when month and year are available and YYYY when only the year is available. Use null as end_date for current positions.
Extract every distinct experience entry found in the CV. Freelance, self-employed, contract, internship, part-time, temporary, consulting, and volunteer roles are valid experience entries.
Concurrent or overlapping jobs are valid and must remain separate entries. Do not omit an experience because its dates overlap another experience.
Freelance is a valid company_name. "Freelance" and "Self-employed" are valid company_name values. Do not merge separate employers or roles into one experience.
Preserve every responsibility bullet belonging to its experience. Use description only for an independent general description explicitly present in the CV; otherwise use null. Never copy a responsibility into description.
Extract every education entry explicitly present in the CV. Group adjacent degree, field, institution, and date lines. "Expected" graduation means is_expected=true.
Return one skill per array item. Do not group comma-separated skills. Keep a parenthetical specialization with its parent skill.
Extract every explicitly listed certification, certificate, professional course, license, or formal training entry. Certification names and years may be on adjacent lines. Recognize CERTIFICATIONS, CERTIFICATES, LICENSES, COURSES, TRAINING, and PROFESSIONAL TRAINING headings, including minor spelling mistakes in clear section context.
Do not classify education as certification or treat a university degree as a certification. Do not classify a normal skill as a certification unless it is listed in a certification, certificate, license, course, or training section.
Certification issuer and expiration_year must be null when not explicitly present. Return an empty certifications array when missing.
Extract nationality and marital status only when explicitly written. Never infer nationality from location, phone number, language, name, employer, country, or address. Never infer marital status. Return null for missing nationality or marital status.

Return one valid JSON object only.
Return exactly these top-level keys:
full_name, email, phone, location, birth_date, nationality, marital_status, summary, experience, education, certifications, skills, languages.

Use null for unavailable nullable scalar values.
Use empty arrays when no experience, education, certifications, skills, or languages exist.
Do not add keys outside the requested contract.
Every experience and education item must include all contract fields.

Experience fields: title, company_name, location, work_mode, start_date, end_date, is_current, description, responsibilities, evidence, confidence_score.
Education fields: degree, field_of_study, institution, start_year, graduation_year, is_expected, description, evidence, confidence_score.
Language fields: name, level.
Certification fields: name, issuer, issue_year, expiration_year, description, evidence, confidence_score.

An empty result is:
{"full_name":null,"email":null,"phone":null,"location":null,"birth_date":null,"nationality":null,"marital_status":null,"summary":null,"experience":[],"education":[],"certifications":[],"skills":[],"languages":[]}

- At most 20 responsibilities per experience.
- Each evidence string must be at most 300 characters.
- Each description must be concise.
- Keep every valid experience supported by the CV; do not omit it merely to shorten the output.

Return one complete JSON object only.
Do not output markdown or code fences.
Do not output explanations before or after the JSON.
Keep evidence concise.
Keep each description and responsibility concise.
PROMPT;
    }
}
