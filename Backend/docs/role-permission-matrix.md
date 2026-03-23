# Role-Permission Matrix (Use Case vs Implementation)

Date: 2026-03-22

Legend:
- `Y` allowed
- `N` blocked
- `Admin Staff` means `role=staff` with `is_admin=1`

## Core use cases

| Capability | Student | Tutor | Staff | Admin Staff | Backend Status |
|---|---:|---:|---:|---:|---|
| Login | Y | Y | Y | Y | Implemented (`auth`) |
| View last login info | Y | Y | Y | Y | Implemented (`dashboard&action=lastLogin`) |
| View own dashboard | Y | Y | Y | Y | Implemented (`dashboard`) |
| Send message | Y | Y | N | N | Implemented (`message`) |
| Create blog post | Y | Y | N | N | Implemented (`blog` write restricted) |
| Comment on blog post | Y | Y | N | N | Implemented (`blog_comment`) |
| Upload document | Y | N | N | N | Implemented (`document` upload) |
| View allocated tutor | Y | N | N | N | Implemented (`allocation&action=myTutor`) |
| View assigned students | N | Y | N | N | Implemented (`allocation&action=assignedStudents`) |
| Comment on student document | N | Y | N | N | Implemented (`document_comment` create/update/delete) |
| Allocate meeting type / create meeting | N | Y | N | N | Implemented (`meeting` create by tutor) |
| Record meeting outcome | N | Y | N | N | Implemented (`meeting` update by tutor) |
| Upload meeting recording | N | Y | N | N | Implemented (`meeting_recording`) |
| Create user account | N | N | Y | Y | Implemented (`user` create staff+) |
| View student/tutor data | N | N | Y | Y | Implemented (`user` list staff+) |
| Allocate personal tutor | N | N | Y | Y | Implemented (`allocation` create staff+) |
| Reallocate tutor | N | N | Y | Y | Implemented (`allocation&action=reallocate`) |
| Bulk allocation | N | N | Y | Y | Implemented (`allocation&action=bulk`) |
| View student/tutor dashboards | N | N | Y | Y | Implemented (`dashboard&action=userDashboard`) |
| View blog posts (admin requirement) | N | N | N | Y | Implemented (admin-staff can read `blog`) |
| Reset any user password | N | N | N | Y | Implemented (`user&action=resetPassword`) |
| View activity logs | N | N | N | Y | Implemented (`report&action=activityLogs`) |
| View exception report | N | N | N | Y | Implemented (`report&action=exceptions`) |
| View statistical report | N | N | N | Y | Implemented (`report&action=statistics`) |

## Endpoint-level notes

- Staff vs Admin model is correctly implemented as one role (`staff`) plus `is_admin` flag.
- `report` controller is admin-only by `is_admin`.
- `user` controller:
  - list/create = staff+
  - update/delete/resetPassword = admin-staff only
- `blog` controller:
  - read = student/tutor/admin-staff
  - write = student/tutor only
- `message` controller is student+tutor only (staff blocked).

## Validation evidence

- `Backend/tasks/smoke_matrix.php`: `24/24 PASS` after fixes in this pass.

## Remaining policy decisions (non-blocking, clarify in report)

1. If normal staff should also view blog, allow `staff` read in `BlogController::requireBlogReadRole()`.
2. If staff should send messages (administrative messaging), re-allow `staff` in `MessageController`.
3. Keep current rules if you want strict use-case alignment exactly as stated.
