# Requirement Compliance Notes

## Security / policy assumptions

1. **All students must have a personal tutor**  
   Enforced at login level:
   - student login is blocked if no active allocation exists.
2. **Inactivity warnings**  
   Sent by scheduled background task:
   - `Backend/tasks/run_inactivity_warning.php`
3. **Email delivery**  
   Depends on SMTP credentials, provider policy, firewall/network.

## New report endpoints

- `GET /Backend/api/index.php?controller=report&action=pageViews&days=30&limit=20`
- `GET /Backend/api/index.php?controller=report&action=browsers&days=30&limit=20`

Both are admin-only and based on `activity_logs`.
