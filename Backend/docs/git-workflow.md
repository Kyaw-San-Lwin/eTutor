# Git Strategy for eTutor (Student Full‑Stack)

This guide describes a complete Git strategy for a student full‑stack project like **eTutor** (HTML/CSS/JS frontend + PHP backend + MySQL database).

## 1. Overall Strategy

The repo should manage **four main parts**:

1. Frontend
2. Backend
3. Database
4. Documentation

### Recommended Repository Structure

```text
etutor-system
│
├── frontend
│   ├── css
│   ├── js
│   ├── components
│   ├── pages
│   └── assets
│
├── backend
│   ├── config
│   ├── controllers
│   ├── models
│   ├── services
│   ├── middleware
│   └── api
│
├── database
│   ├── migrations
│   ├── seeds
│   └── schema.sql
│
├── docs
│   ├── api-documentation.md
│   ├── system-architecture.md
│   └── setup-guide.md
│
├── .gitignore
└── README.md
```

## 2. Database Version Control

Track database changes using **migrations**.

### Folder Structure

```text
database
│
├── migrations
│   ├── 001_create_users_table.sql
│   ├── 002_create_courses_table.sql
│   ├── 003_create_messages_table.sql
│
├── seeds
│   ├── sample_users.sql
│   └── sample_courses.sql
│
└── schema.sql
```

### Migration Naming Style

Each migration is one database change.

Example:

```sql
-- 001_create_users_table.sql

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100),
    password VARCHAR(255),
    role ENUM('student','tutor','admin'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Database Update Workflow

Example: Messaging system

1. Create migration file
2. Commit
3. Push

```bash
git add database/migrations/004_create_messages_table.sql
git commit -m "Add messages table migration"
git push origin feature-messaging
```

## 3. Backend API Versioning

Version your APIs so the frontend doesn’t break on changes.

### API Folder Structure

```text
backend/api
│
├── v1
│   ├── auth
│   │   ├── login.php
│   │   └── register.php
│   │
│   ├── courses
│   │   ├── create.php
│   │   ├── list.php
│   │   └── enroll.php
│   │
│   └── messages
│       ├── send.php
│       └── get.php
│
└── v2
```

Example endpoint:

```
/api/v1/auth/login.php
/api/v1/messages/send.php
```

## 4. Frontend Component Commits

Commit by **component or feature**, not the entire project.

Example structure:

```text
frontend
│
├── components
│   ├── navbar
│   │   ├── navbar.html
│   │   ├── navbar.css
│   │   └── navbar.js
│
├── pages
│   ├── login.html
│   ├── dashboard.html
│   └── messaging.html
```

Good commit messages:

```
Add navbar component
Create login page UI
Add messaging chat interface
Connect messaging API to frontend
Fix responsive navbar layout
```

## 5. Feature‑Based Branching

Use feature branches for development.

Branch types:

```
main
develop
feature/*
bugfix/*
hotfix/*
```

Workflow:

```
develop → feature branch → develop → main
```

## 6. Team Collaboration Workflow

```bash
git pull origin develop
git checkout -b feature-messaging
```

Commit regularly:

```bash
git commit -m "Create messages table"
git commit -m "Add send message API"
git commit -m "Add chat UI"
```

Push and merge:

```bash
git push origin feature-messaging
git checkout develop
git merge feature-messaging
```

Release:

```bash
git checkout main
git merge develop
git push origin main
```

## 7. Issue Tracking (Optional)

Use GitHub Issues and reference them in commits:

```
git commit -m "Implement login API (fixes #12)"
```

## 8. Release Version Strategy

Use tags:

```
v0.1  login system
v0.2  course management
v0.3  messaging system
v1.0  full system release
```

```bash
git tag v1.0
git push origin v1.0
```

## 9. Important .gitignore

```
.env
/vendor
/node_modules
*.log
*.cache
config/database_local.php
```

## 10. Daily Development Routine

```text
1. git pull
2. create feature branch
3. write code
4. commit frequently
5. push branch
6. merge to develop
```

## Summary

```
Repository
├── frontend (UI)
├── backend (PHP APIs)
├── database (migrations)
├── docs (documentation)
│
Workflow
main → stable
develop → development
feature branches → new features
```
