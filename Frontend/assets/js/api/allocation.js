async function loadMyTutor() {
  return apiRequest("allocation", "myTutor", "GET", null);
}

async function loadAssignedStudents() {
  return apiRequest("allocation", "assignedStudents", "GET", null);
}
