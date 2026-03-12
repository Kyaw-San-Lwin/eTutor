# Day 20 Testing Evidence (Logging, Error Handling, Health Check)

Use this sheet as coursework evidence.

## 1) Pre-Check

Run from project root: `C:\xampp\htdocs\eTutor`

```bat
php -l Backend\api\index.php
php -l Backend\middleware\authMiddleware.php
php -l Backend\middleware\activityMiddleware.php
php -l Backend\core\ErrorHandler.php
php -l Backend\controllers\HealthController.php
```

Expected: `No syntax errors detected` for all files.

## 2) Health Check Endpoint (Public)

```bat
curl.exe -i "http://localhost/eTutor/Backend/api/index.php?controller=health"
```

Expected:
- HTTP status: `200 OK`
- JSON contains:
  - `"status":"OK"`
  - `"service":"eTutor API"`
  - `"time":"YYYY-MM-DD HH:MM:SS"`

## 3) Automatic API Request Logging

### 3.1 Generate a valid admin/staff token

```bat
php Backend\tasks\gen_admin_token.php
```

Copy the returned JWT value.

### 3.2 Call one protected endpoint

```bat
curl.exe -i -H "Authorization: Bearer YOUR_TOKEN_HERE" "http://localhost/eTutor/Backend/api/index.php?controller=report&action=statistics"
```

Expected:
- HTTP status: `200` (if token/permissions are valid)

### 3.3 Verify log row in DB

```bat
/c/xampp/mysql/bin/mysql.exe -h localhost -P 3307 -u root -e "USE etutor; SELECT log_id,user_id,page_visited,activity_type,ip_address,access_time FROM activity_logs ORDER BY log_id DESC LIMIT 5;"
```

Expected:
- New row appears with:
  - `page_visited` like `report:statistics`
  - `activity_type` = `API access`
  - valid `user_id`, `ip_address`, `access_time`

## 4) Global Error Handler

### 4.1 Standalone verification (controlled exception)

```bat
php -r "require 'Backend/core/Response.php'; require 'Backend/core/ErrorHandler.php'; set_exception_handler(['ErrorHandler','handleException']); throw new Exception('test');"
```

Expected JSON:

```json
{"success":false,"message":"Server error"}
```

### 4.2 API still returns structured errors (no PHP fatal output)

```bat
curl.exe -i "http://localhost/eTutor/Backend/api/index.php?controller=invalid_controller"
```

Expected:
- HTTP status: `404`
- JSON response structure (not raw PHP fatal/stack trace)

## 5) Screenshot Evidence to Attach in Report

Capture and attach:
- health endpoint response (`200 + status OK`)
- one protected API call with bearer token
- latest `activity_logs` rows showing `API access`
- error-handler JSON output from controlled exception

