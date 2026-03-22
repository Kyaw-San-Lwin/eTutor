# Frontend-Backend Integration Check

Date: 2026-03-23

## 1) Backend health

- Smoke matrix: `24/24 PASS` (`Backend/tasks/smoke_matrix.php`)
- Fixed during this pass:
  - `report_activityLogsCsv_admin` 500 -> 200
  - `doc_comment_list_student` 500 -> 200

## 2) Integrated pages (real API wiring)

- Auth:
  - `Frontend/Pages/Auth/Login.html` -> `auth` login flow via `login-page.js`
- Student:
  - `Student_Dashboard.html` -> `dashboard`, `meeting`, `blog_comment`
  - `Student_Profile.html`, `Tutor_Profile.html`, `Edit_Profile.html` -> `user/me`, `user/updateMe`, `user/uploadMyPhoto`, `user/changeMyPassword`, `dashboard/lastLogin`
  - `Messaging.html` -> `message` list/create/update(read), `allocation/myTutor`
  - `Blog_Post.html` -> `blog`, `blog_comment`
  - `Document.html` -> `document` upload/list
  - `Comment.html` -> `document_comment` + `document`
  - `Meeting.html` -> `meeting` + `allocation/myTutor`
- Tutor:
  - `Tutor_Dashboard.html` -> `dashboard`, `meeting`, `blog_comment`
  - `Tutor_Profile.html`, `Edit_Profile.html` -> same profile APIs as above
  - `Tutor_Messaging.html` -> `message` + `allocation/assignedStudents`
  - `Tutor_Blog.html` -> `blog`, `blog_comment`
  - `Tutor_Document.html` -> `document` list + student panel data from `allocation/assignedStudents`
  - `Tutor_Meeting.html` -> `meeting` create/list + `allocation/assignedStudents`
  - `Student_List.html` (Tutor) -> `allocation/assignedStudents`
- Staff:
  - `Staff_Dashboard.html` -> now wired (`staff-dashboard-page.js`)
  - `Create_user.html` -> now wired to real `user` create API (`create-user-page.js`)
  - `Student_List.html`, `Tutor_List.html` -> `user` list
  - `Staff_Profile.html`, `Edit_Profile.html` -> self-profile APIs
  - `Blog_Post.html` -> blog read flow (staff role)
  - `Allocation.html` -> now wired to `allocation` + `user`
  - `Reallocation.html` -> now wired to `allocation/reallocate` + `user`
  - `Exception_Report.html` -> now wired to `report&action=exceptions`
  - `Statistical_Report.html` -> now wired to `report&action=statistics` + `report&action=activityLogs`
  - `Messaging.html` -> added as non-crashing placeholder page for current policy

## 3) Gaps still to finish

- Staff blog permissions:
  - backend allows staff-admin to read blog only; write is student/tutor only
  - if staff should create blog in UI, backend policy must be changed intentionally
- Optional UX enhancement:
  - replace static chart values on `Staff_Dashboard.html` with live report data

## 4) Integration readiness summary

- Core student+tutor flows: integrated
- Core staff operational flows (list users, allocate/reallocate, profile): integrated
- Remaining required work before final demo:
  1. Align blog write permissions with final business rule
  2. Optional: make staff dashboard charts use live backend data
