"""Deterministic professional-domain catalog built without external data."""

from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class DomainCatalog:
    """Professional vocabulary used to create synthetic Candidates and Jobs."""

    name: str
    titles: tuple[str, ...]
    core_skills: tuple[str, ...]
    transferable_skills: tuple[str, ...]
    adjacent_domains: tuple[str, ...]
    responsibilities: tuple[str, ...]


DOMAINS: tuple[DomainCatalog, ...] = (
    DomainCatalog(
        name="Backend Engineering",
        titles=("Backend Engineer", "API Developer", "Platform Engineer"),
        core_skills=("python", "php", "rest api", "sql", "system design", "testing"),
        transferable_skills=("problem solving", "communication", "data literacy"),
        adjacent_domains=("Data Engineering", "DevOps / Cloud"),
        responsibilities=(
            "Design reliable service interfaces.",
            "Review backend changes and automated tests.",
            "Improve application performance and observability.",
        ),
    ),
    DomainCatalog(
        name="Frontend Engineering",
        titles=("Frontend Engineer", "Web UI Developer", "JavaScript Engineer"),
        core_skills=("javascript", "typescript", "react", "html", "css", "web testing"),
        transferable_skills=("problem solving", "communication", "user empathy"),
        adjacent_domains=("UI/UX Design", "Quality Assurance"),
        responsibilities=(
            "Build accessible web interfaces.",
            "Translate product requirements into UI components.",
            "Maintain browser-based automated tests.",
        ),
    ),
    DomainCatalog(
        name="Mobile Development",
        titles=("Mobile Developer", "Android Engineer", "iOS Engineer"),
        core_skills=("kotlin", "swift", "mobile ui", "rest api", "testing", "app lifecycle"),
        transferable_skills=("problem solving", "communication", "user empathy"),
        adjacent_domains=("Frontend Engineering", "Quality Assurance"),
        responsibilities=(
            "Build reliable mobile application flows.",
            "Integrate mobile clients with service APIs.",
            "Diagnose device and lifecycle issues.",
        ),
    ),
    DomainCatalog(
        name="Data Engineering",
        titles=("Data Engineer", "ETL Developer", "Analytics Engineer"),
        core_skills=("python", "sql", "data modeling", "etl", "data quality", "warehousing"),
        transferable_skills=("problem solving", "communication", "data literacy"),
        adjacent_domains=("Backend Engineering", "Data Analysis"),
        responsibilities=(
            "Develop reliable data ingestion pipelines.",
            "Model analytical datasets and lineage.",
            "Monitor data quality and processing failures.",
        ),
    ),
    DomainCatalog(
        name="Data Analysis",
        titles=("Data Analyst", "Business Intelligence Analyst", "Reporting Analyst"),
        core_skills=(
            "sql",
            "statistics",
            "data visualization",
            "reporting",
            "spreadsheets",
            "analysis",
        ),
        transferable_skills=("communication", "data literacy", "stakeholder management"),
        adjacent_domains=("Data Engineering", "Finance / Accounting"),
        responsibilities=(
            "Analyze trends and communicate findings.",
            "Build reproducible business reports.",
            "Translate stakeholder questions into metrics.",
        ),
    ),
    DomainCatalog(
        name="DevOps / Cloud",
        titles=("DevOps Engineer", "Cloud Engineer", "Site Reliability Engineer"),
        core_skills=("linux", "cloud", "containers", "ci cd", "monitoring", "infrastructure"),
        transferable_skills=("problem solving", "communication", "incident management"),
        adjacent_domains=("Backend Engineering", "Cybersecurity"),
        responsibilities=(
            "Automate deployment and infrastructure workflows.",
            "Operate monitoring and incident response practices.",
            "Improve service reliability and capacity.",
        ),
    ),
    DomainCatalog(
        name="Cybersecurity",
        titles=("Security Analyst", "Application Security Engineer", "Security Engineer"),
        core_skills=(
            "threat modeling",
            "network security",
            "iam",
            "security testing",
            "risk",
            "incident response",
        ),
        transferable_skills=("problem solving", "communication", "incident management"),
        adjacent_domains=("DevOps / Cloud", "Quality Assurance"),
        responsibilities=(
            "Assess security risks and control effectiveness.",
            "Investigate security alerts and incidents.",
            "Guide teams on secure engineering practices.",
        ),
    ),
    DomainCatalog(
        name="Quality Assurance",
        titles=("QA Engineer", "Test Automation Engineer", "Quality Analyst"),
        core_skills=(
            "test planning",
            "test automation",
            "api testing",
            "web testing",
            "defect analysis",
            "quality",
        ),
        transferable_skills=("problem solving", "communication", "attention to detail"),
        adjacent_domains=("Frontend Engineering", "Mobile Development"),
        responsibilities=(
            "Design risk-based test strategies.",
            "Automate regression and integration checks.",
            "Analyze defects and improve quality feedback.",
        ),
    ),
    DomainCatalog(
        name="Product Management",
        titles=("Product Manager", "Product Owner", "Technical Product Manager"),
        core_skills=(
            "product strategy",
            "roadmapping",
            "requirements",
            "analytics",
            "prioritization",
            "discovery",
        ),
        transferable_skills=("communication", "stakeholder management", "user empathy"),
        adjacent_domains=("UI/UX Design", "Digital Marketing"),
        responsibilities=(
            "Define product outcomes and priorities.",
            "Coordinate discovery with users and stakeholders.",
            "Measure delivery impact and product adoption.",
        ),
    ),
    DomainCatalog(
        name="UI/UX Design",
        titles=("Product Designer", "UX Designer", "UI Designer"),
        core_skills=(
            "user research",
            "interaction design",
            "prototyping",
            "visual design",
            "accessibility",
            "design systems",
        ),
        transferable_skills=("communication", "user empathy", "stakeholder management"),
        adjacent_domains=("Frontend Engineering", "Product Management"),
        responsibilities=(
            "Research user needs and workflow constraints.",
            "Create accessible interaction prototypes.",
            "Maintain coherent interface patterns.",
        ),
    ),
    DomainCatalog(
        name="Digital Marketing",
        titles=("Digital Marketing Specialist", "Growth Marketer", "Content Strategist"),
        core_skills=(
            "campaign planning",
            "seo",
            "content strategy",
            "web analytics",
            "conversion",
            "market research",
        ),
        transferable_skills=("communication", "data literacy", "stakeholder management"),
        adjacent_domains=("Product Management", "Data Analysis"),
        responsibilities=(
            "Plan measurable digital campaigns.",
            "Analyze acquisition and conversion performance.",
            "Coordinate content with market research.",
        ),
    ),
    DomainCatalog(
        name="Finance / Accounting",
        titles=("Financial Analyst", "Accountant", "Management Accountant"),
        core_skills=(
            "financial reporting",
            "accounting",
            "budgeting",
            "forecasting",
            "spreadsheets",
            "controls",
        ),
        transferable_skills=("communication", "data literacy", "attention to detail"),
        adjacent_domains=("Data Analysis", "Product Management"),
        responsibilities=(
            "Prepare accurate financial reporting.",
            "Analyze budgets, forecasts, and variances.",
            "Maintain financial controls and reconciliations.",
        ),
    ),
)

DOMAIN_BY_NAME: dict[str, DomainCatalog] = {domain.name: domain for domain in DOMAINS}
