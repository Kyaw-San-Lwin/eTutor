# Postman Demo Checklist (Day 20)

Use this for live demonstration and screenshot evidence.

## Collection Setup

Create a Postman collection: `eTutor Day20 Evidence`

Set collection variables:

- `baseUrl` = `http://localhost/eTutor/Backend/api/index.php`
- `token` = (leave empty first, set after login/token generation)

Default header for protected requests:

- `Authorization: Bearer {{token}}`

## Folder A: Health Check (Public)

### A1. Health endpoint
- Method: `GET`
- URL: `{{baseUrl}}?controller=health`

Expected:
- Status `200`
- JSON keys: `status`, `service`, `time`, `success`, `message`
- `status` value is `OK`

Suggested Tests tab script:

```javascript
pm.test("Status is 200", () => pm.response.to.have.status(200));
const body = pm.response.json();
pm.test("Health status OK", () => pm.expect(body.status).to.eql("OK"));
pm.test("Has service and time", () => {
  pm.expect(body).to.have.property("service");
  pm.expect(body).to.have.property("time");
});
```

## Folder B: Auth/Token Prep

Use either route below:

1. Login request (if credentials available):
- Method: `POST`
- URL: `{{baseUrl}}?controller=auth`
- JSON body:
```json
{
  "login": "admin@example.com",
  "password": "your_password"
}
```

2. Or generate token from terminal:
- `php Backend\tasks\gen_admin_token.php`
- Manually paste output into Postman variable `token`.

If using login response, set token in Tests tab:

```javascript
const body = pm.response.json();
if (body.access_token) {
  pm.collectionVariables.set("token", body.access_token);
}
```

## Folder C: Request Logging Evidence

### C1. Protected endpoint call
- Method: `GET`
- URL: `{{baseUrl}}?controller=report&action=statistics`
- Header: `Authorization: Bearer {{token}}`

Expected:
- Status `200` (valid admin/staff token)
- This call should create a row in `activity_logs` with:
  - `page_visited`: `report:statistics`
  - `activity_type`: `API access`

Suggested Tests:

```javascript
pm.test("Protected endpoint returns success", () => {
  pm.expect(pm.response.code).to.be.oneOf([200]);
});
```

### C2. DB verification (terminal evidence)

Run outside Postman:

```bat
/c/xampp/mysql/bin/mysql.exe -h localhost -P 3307 -u root -e "USE etutor; SELECT log_id,user_id,page_visited,activity_type,ip_address,access_time FROM activity_logs ORDER BY log_id DESC LIMIT 5;"
```

Take screenshot with the latest inserted log row.

## Folder D: Error Handling Evidence

### D1. Invalid controller
- Method: `GET`
- URL: `{{baseUrl}}?controller=invalid_controller`

Expected:
- Status `404`
- JSON response (not raw PHP fatal output)

Suggested Tests:

```javascript
pm.test("Returns 404 for invalid controller", () => pm.response.to.have.status(404));
pm.test("Response is JSON", () => {
  pm.expect(pm.response.headers.get("Content-Type")).to.include("application/json");
});
```

### D2. Unauthorized protected call
- Method: `GET`
- URL: `{{baseUrl}}?controller=report&action=statistics`
- Remove Authorization header temporarily

Expected:
- Status `401`
- JSON with token-related message

## Folder E: 409 Conflict Evidence (Duplicate Key)

### E1. Create duplicate user (admin only)
- Method: `POST`
- URL: `{{baseUrl}}?controller=user`
- Header: `Authorization: Bearer {{token}}`
- JSON body:
```json
{
  "user_name": "existing_user",
  "email": "existing_email@example.com",
  "password": "Password123!",
  "role_id": 2
}
```

Steps:
1. Run once with a unique `user_name` + `email` to create the user.
2. Run again with the same values to trigger conflict.

Expected:
- First call: `201`
- Second call: `409` with message `Username or email already exists`

### E2. Duplicate allocation (admin only)
- Method: `POST`
- URL: `{{baseUrl}}?controller=allocation`
- Header: `Authorization: Bearer {{token}}`
- JSON body:
```json
{
  "student_id": 1,
  "tutor_id": 1,
  "status": "active"
}
```

Steps:
1. Create allocation once.
2. Re-run with same `student_id` + `tutor_id` (assuming unique constraint exists).

Expected:
- First call: `201`
- Second call: `409` with message `Allocation conflicts with an existing record`

## Viva Script (2-3 minutes)

1. Run A1 and show health check is public and operational.
2. Run C1 with token and show protected endpoint works.
3. Show DB query result from C2 proving automatic API request logging.
4. Run D1/D2 to show standardized safe error responses.

## Screenshot List for Report Appendix

1. Postman A1 response (`200`, `status=OK`)
2. Postman C1 protected request (`200`)
3. MySQL output showing latest `activity_logs` row
4. Postman D1 (`404` JSON response)
5. Postman D2 (`401` JSON response)
