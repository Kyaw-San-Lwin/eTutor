async function loadDashboard() {
  return apiRequest("dashboard", "", "GET", null);
}

async function loadLastLogin() {
  return apiRequest("dashboard", "lastLogin", "GET", null);
}
