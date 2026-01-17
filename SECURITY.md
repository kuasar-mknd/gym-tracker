# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report

**⚠️ Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email us at: **[INSERT SECURITY EMAIL]**

Include in your report:

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested fixes (optional)

### What to Expect

1. **Acknowledgment** — We'll confirm receipt within 48 hours
2. **Investigation** — We'll investigate and keep you updated
3. **Fix** — We'll develop and test a fix
4. **Disclosure** — We'll coordinate disclosure with you
5. **Credit** — We'll credit you in the release notes (if desired)

### Scope

The following are in scope:

- Authentication bypass
- SQL injection
- XSS (Cross-Site Scripting)
- CSRF (Cross-Site Request Forgery)
- Sensitive data exposure
- Server-side request forgery (SSRF)
- Remote code execution

### Out of Scope

- Rate limiting issues
- Denial of Service (DoS)
- Social engineering
- Physical security
- Issues in dependencies (report upstream)

## Security Best Practices

When contributing:

- Never commit secrets or credentials
- Use environment variables for sensitive config
- Validate and sanitize all user input
- Use prepared statements for database queries
- Follow Laravel security best practices

---

Thank you for helping keep GymTracker secure! 🔒
