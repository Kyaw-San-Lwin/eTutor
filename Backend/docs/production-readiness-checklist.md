# Production Readiness Checklist (eTutor Backend)

Status date: 2026-03-10

This is a practical checklist for production use. Mark each item as `DONE`, `PARTIAL`, or `MISSING`.

## 1) Secrets and Configuration
- MISSING: Move all secrets to environment variables or a secret manager.
- MISSING: Rotate JWT secrets and set strong, unique values per environment.
- MISSING: Production config for DB host/user/pass/port (no defaults).
- MISSING: Disable verbose error output in production.

## 2) Security Hardening
- MISSING: Rate limiting on auth + write endpoints.
- MISSING: Account lockout or progressive delay on failed login.
- MISSING: CORS restricted to frontend domain(s).
- MISSING: Security headers (HSTS, X-Content-Type-Options, X-Frame-Options, CSP).
- MISSING: File upload malware scanning (or external validation pipeline).
- PARTIAL: Input validation (present in most endpoints, needs full audit).

## 3) Authentication and Authorization
- PARTIAL: JWT auth (implemented), but no token revocation list.
- MISSING: Refresh token rotation + reuse detection.
- MISSING: Session invalidation on password reset.
- PARTIAL: Role checks (implemented), but no centralized policy enforcement.

## 4) Observability
- PARTIAL: Activity logs exist, but no request IDs or structured log format.
- MISSING: Centralized log collection (file/ELK/CloudWatch).
- MISSING: Error monitoring (Sentry or equivalent).
- MISSING: Metrics (latency, error rate, throughput).

## 5) Reliability and Operations
- MISSING: Database migrations with versioning.
- MISSING: Automated backups and restore tests.
- MISSING: Health check with DB connectivity and dependency checks.
- MISSING: Deployment scripts (staging/production).
- MISSING: Resource limits and timeouts (PHP max execution, DB timeouts).

## 6) Testing and Quality Gates
- MISSING: Unit tests for controllers/services.
- MISSING: Integration tests for core API flows.
- MISSING: Load testing on message/blog/document endpoints.
- PARTIAL: Manual testing via Postman + smoke matrix.

## 7) Data Protection and Compliance
- MISSING: Enforce HTTPS in production.
- MISSING: Data retention policy (logs/uploads).
- MISSING: Access control audit for every endpoint.

## 8) Deployment Environment
- MISSING: Separate `.env` for dev/staging/prod.
- MISSING: Docker or repeatable deployment packaging.
- MISSING: CI/CD pipeline for build/test/deploy.

## Summary

For coursework and demo this backend is strong. For production use, you should treat this list as mandatory.

