# eTutor Git Workflow

This repository uses a simple student‑friendly Git workflow that keeps `main` stable and allows safe feature development.

## Branches

- `main`: stable release
- `develop`: integration branch
- `feature/*`: new features
- `bugfix/*`: fixes

## Setup

```bash
git clone <your-repo-url>
cd eTutor
git checkout -b develop
git push -u origin develop
```

## Start a Feature

```bash
git checkout develop
git pull origin develop
git checkout -b feature-messaging
```

## Commit Changes

```bash
git add .
git commit -m "Add message send API"
git push -u origin feature-messaging
```

## Merge Feature into Develop

```bash
git checkout develop
git pull origin develop
git merge feature-messaging
git push origin develop
```

## Release to Main

```bash
git checkout main
git pull origin main
git merge develop
git push origin main
```

## Recommended Commit Messages

- `Add login validation`
- `Fix sidebar spacing`
- `Create document upload API`

## .gitignore Essentials

```
.env
/vendor
/node_modules
*.log
.DS_Store
```

## Daily Habit

```bash
git pull
git checkout -b feature-xyz
git add .
git commit -m "Describe change"
git push -u origin feature-xyz
```
