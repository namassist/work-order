/**
 * Email domain matching for the registration form. The server decides
 * (Company::allowsEmailDomain()); this mirrors it so the form can pre-select
 * the company and disable the ones that cannot match.
 */
export type RegistrationCompany = {
    id: number;
    name: string;
    email_domains: string[];
};

/** The lowercase domain after the last "@", or null while there is none. */
export function emailDomain(email: string): string | null {
    const at = email.lastIndexOf('@');
    const domain =
        at < 0
            ? ''
            : email
                  .slice(at + 1)
                  .trim()
                  .toLowerCase();

    return domain === '' ? null : domain;
}

/** Whether the email's domain is exactly one of the company's domains. */
export function companyAllowsEmail(
    company: RegistrationCompany,
    email: string,
): boolean {
    const domain = emailDomain(email);

    return (
        domain !== null &&
        company.email_domains.some(
            (allowed) => allowed.toLowerCase() === domain,
        )
    );
}

/** The company whose domains include the email's, if any (domains are unique across companies). */
export function companyForEmail<T extends RegistrationCompany>(
    companies: T[],
    email: string,
): T | null {
    return (
        companies.find((company) => companyAllowsEmail(company, email)) ?? null
    );
}
