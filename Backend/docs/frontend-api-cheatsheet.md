# eTutor Frontend API Cheatsheet

Base URL:

```text
http://localhost/eTutor/Backend/api/index.php
```

Auth header (all except login/refresh):

```text
Authorization: Bearer <access_token>
```

Content type:
- JSON endpoints: `Content-Type: application/json`
- Upload endpoints: `multipart/form-data`

## 1) Auth

### Login
`POST ?controller=auth`

```json
{
  "login": "admin@example.com",
  "password": "your_password"
}
```

### Refresh token
`POST ?controller=auth&action=refresh`

```json
{
  "refresh_token": "..."
}
```

### Request password reset token
`POST ?controller=auth&action=requestPasswordReset`

```json
{
  "login": "admin@example.com"
}
```

### Reset password by token
`POST ?controller=auth&action=resetPasswordByToken`

```json
{
  "token": "reset_token_from_previous_call",
  "new_password": "NewPassword123!"
}
```

## 2) Dashboard

### Current user dashboard
`GET ?controller=dashboard`

### Last login
`GET ?controller=dashboard&action=lastLogin`

### Staff view tutor/student dashboard
`GET ?controller=dashboard&action=userDashboard&user_id=2`

## 3) User (admin staff only)

### List
`GET ?controller=user`

Optional:
`GET ?controller=user&limit=20&offset=0&include_inactive=1`

### Create
`POST ?controller=user`

```json
{
  "user_name": "john",
  "email": "john@example.com",
  "password": "Password123!",
  "role_id": 2
}
```

### Update
`PUT ?controller=user`

```json
{
  "id": 10,
  "user_name": "john2",
  "email": "john2@example.com",
  "role_id": 2
}
```

### Delete
`DELETE ?controller=user`

```json
{ "id": 10 }
```

### Reset password
`POST ?controller=user&action=resetPassword`

```json
{
  "id": 10,
  "new_password": "NewPassword123!"
}
```

## 4) Message

### List messages
`GET ?controller=message`

Optional:
`GET ?controller=message&with_user_id=5`
`GET ?controller=message&limit=50&offset=0`

### Send
`POST ?controller=message`

```json
{
  "receiver_id": 5,
  "message": "Hello"
}
```

### Mark status
`PUT ?controller=message`

```json
{
  "id": 20,
  "status": "read"
}
```

## 5) Blog

### List
`GET ?controller=blog`

Optional filters:
- `limit`, `offset`
- `author_id`
- `date_from=YYYY-MM-DD`
- `date_to=YYYY-MM-DD`
- `q` (search in title/content)

### Create
`POST ?controller=blog`

```json
{
  "title": "My Blog",
  "content": "Blog content"
}
```

### Update
`PUT ?controller=blog`

```json
{
  "id": 1,
  "title": "Updated",
  "content": "Updated content"
}
```

### Delete
`DELETE ?controller=blog`

```json
{ "id": 1 }
```

## 6) Blog Comment

### List
`GET ?controller=blog_comment`

Optional:
`GET ?controller=blog_comment&post_id=1`

### Create
`POST ?controller=blog_comment`

```json
{
  "post_id": 1,
  "comment": "Nice post"
}
```

### Update
`PUT ?controller=blog_comment`

```json
{
  "id": 1,
  "comment": "Edited comment"
}
```

### Delete
`DELETE ?controller=blog_comment`

```json
{ "id": 1 }
```

## 7) Allocation

### List (admin)
`GET ?controller=allocation`

### Create (admin)
`POST ?controller=allocation`

```json
{
  "student_id": 1,
  "tutor_id": 1,
  "status": "active"
}
```

### Update (admin)
`PUT ?controller=allocation`

```json
{
  "id": 1,
  "student_id": 1,
  "tutor_id": 2,
  "status": "active"
}
```

### Delete (admin)
`DELETE ?controller=allocation`

```json
{ "id": 1 }
```

### Bulk allocation (staff)
`POST ?controller=allocation&action=bulk`

```json
{
  "status": "active",
  "allocations": [
    { "student_id": 1, "tutor_id": 1 },
    { "student_id": 2, "tutor_id": 1 }
  ]
}
```

### Reallocate (staff)
`POST ?controller=allocation&action=reallocate`

```json
{
  "student_id": 1,
  "new_tutor_id": 3
}
```

### Student view allocated tutor
`GET ?controller=allocation&action=myTutor`

### Tutor view assigned students
`GET ?controller=allocation&action=assignedStudents`

## 8) Meeting

### List
`GET ?controller=meeting`

### Create
`POST ?controller=meeting`

```json
{
  "student_id": 1,
  "tutor_id": 1,
  "meeting_date": "2026-03-20",
  "meeting_time": "10:00:00",
  "meeting_type": "virtual",
  "meeting_platform": "Google Meet",
  "meeting_link": "https://meet.google.com/abc-defg-hij",
  "status": "scheduled"
}
```

Physical example additions:

```json
{
  "meeting_type": "physical",
  "meeting_location": "Room A101"
}
```

### Update
`PUT ?controller=meeting`

```json
{
  "id": 1,
  "student_id": 1,
  "tutor_id": 1,
  "meeting_date": "2026-03-21",
  "meeting_time": "11:00:00",
  "meeting_type": "virtual",
  "meeting_platform": "Zoom",
  "meeting_link": "https://zoom.us/j/123456789",
  "status": "scheduled",
  "outcome": "Progress discussed"
}
```

### Delete (admin)
`DELETE ?controller=meeting`

```json
{ "id": 1 }
```

## 9) Meeting Recording (one recording per meeting)

### List
`GET ?controller=meeting_recording`

Optional:
`GET ?controller=meeting_recording&meeting_id=1`

### Create (JSON)
`POST ?controller=meeting_recording`

```json
{
  "meeting_id": 1,
  "file_path": "/Backend/uploads/meeting_recordings/file.mp4"
}
```

### Create (multipart upload)
`POST ?controller=meeting_recording`

Form-data:
- `meeting_id` = `1`
- `file` = (video file)

### Update
`PUT ?controller=meeting_recording`

```json
{
  "id": 1,
  "file_path": "/Backend/uploads/meeting_recordings/new.mp4"
}
```

### Delete
`DELETE ?controller=meeting_recording`

```json
{ "id": 1 }
```

## 10) Document

### List
`GET ?controller=document`

Optional filters:
- `limit`, `offset`
- `student_id` (admin/staff usage)
- `date_from=YYYY-MM-DD`
- `date_to=YYYY-MM-DD`
- `q` (search in file_path)

### Create (JSON)
`POST ?controller=document`

```json
{
  "student_id": 1,
  "file_path": "/Backend/uploads/documents/file.pdf"
}
```

### Create (multipart upload)
`POST ?controller=document`

Form-data:
- `student_id` = `1` (student users may omit depending on backend role flow)
- `file` = (pdf/doc/docx/txt/jpg/jpeg/png)

### Update (admin)
`PUT ?controller=document`

```json
{
  "id": 1,
  "student_id": 1,
  "file_path": "/Backend/uploads/documents/new.pdf"
}
```

### Delete (admin)
`DELETE ?controller=document`

```json
{ "id": 1 }
```

## 11) Document Comment (one comment per document)

### List
`GET ?controller=document_comment`

Optional:
`GET ?controller=document_comment&document_id=1`

### Create
`POST ?controller=document_comment`

```json
{
  "document_id": 1,
  "comment": "Please improve section 2."
}
```

### Update
`PUT ?controller=document_comment`

```json
{
  "id": 1,
  "comment": "Updated feedback"
}
```

### Delete
`DELETE ?controller=document_comment`

```json
{ "id": 1 }
```

## 12) Report (admin staff only)

### Activity summary
`GET ?controller=report`

### Statistics
`GET ?controller=report&action=statistics`

### Exceptions
`GET ?controller=report&action=exceptions`

### Overview
`GET ?controller=report&action=overview`

### Activity logs with filters
`GET ?controller=report&action=activityLogs&limit=10&offset=0`

Optional filters:
- `user_id`
- `activity_type`
- `page_visited`
- `from_date=YYYY-MM-DD`
- `to_date=YYYY-MM-DD`

### Export CSV
`GET ?controller=report&action=activityLogsCsv&limit=1000`

## 13) Inactivity (admin staff only)

### View inactivity report
`GET ?controller=inactivity`

### Trigger warnings
`GET ?controller=inactivity&action=warn&days=28`

## Frontend Notes

1. Non-auth calls require bearer token.
2. Handle status codes:
   - `200/201` success
   - `400` validation errors
   - `401` token missing/invalid
   - `403` permission denied
   - `404` not found
   - `409` one-comment/one-recording conflict
3. One meeting can have only one recording.
4. One document can have only one tutor comment.
5. List endpoints return pagination metadata in `meta` when applicable.
6. Soft delete behavior:
   - user delete => account set to inactive
   - blog/document delete => soft delete when `deleted_at` column exists
