# Inactivity Warning Auto-Run

This project sends inactivity warnings from:

- `Backend/tasks/run_inactivity_warning.php`
- default threshold: `28` days

## Windows Task Scheduler (XAMPP)

1. Open **Task Scheduler** -> **Create Task**.
2. Name: `eTutor Inactivity Warning`.
3. Trigger: Daily (example: 08:00).
4. Action:
   - Program/script: `C:\xampp\htdocs\eTutor\Backend\tasks\run_inactivity_warning.bat`
   - Add arguments: `28`
   - Start in: `C:\xampp\htdocs\eTutor`
5. Save and run once manually to verify.

Logs:

- `Backend/logs/inactivity_scheduler.log`

## Verification (must prove scheduler is running)

1. Run once manually:
   - `Backend/tasks/run_inactivity_warning.bat 28`
2. Confirm log entry appended:
   - `Backend/logs/inactivity_scheduler.log`
3. In Task Scheduler, capture evidence:
   - task name
   - trigger schedule
   - **Last Run Time**
   - **Next Run Time**
   - **Last Run Result = 0x0**
4. Save screenshot for report evidence (recommended path):
   - `Backend/docs/evidence/task-scheduler-inactivity.png`

## Linux Cron (optional)

```bash
0 8 * * * /usr/bin/php /var/www/html/eTutor/Backend/tasks/run_inactivity_warning.php 28 >> /var/www/html/eTutor/Backend/logs/inactivity_scheduler.log 2>&1
```

## Notes

- Email delivery depends on SMTP and network/firewall.
- If mail is disabled in `.env`, warnings are still computed but no emails are sent.
