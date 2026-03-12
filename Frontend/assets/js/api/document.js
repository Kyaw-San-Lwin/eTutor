async function loadDocuments() {
  return apiRequest("document", "", "GET", null);
}

async function uploadDocument(file, studentId = null) {
  const formData = new FormData();
  if (studentId) {
    formData.append("student_id", String(studentId));
  }
  formData.append("file", file);
  return apiRequest("document", "", "POST", formData);
}
