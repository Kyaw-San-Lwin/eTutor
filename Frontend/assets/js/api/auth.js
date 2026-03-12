async function loginUser(login, password) {
  const res = await apiRequest("auth", "", "POST", { login, password });
  if (!res) return;

  if (!res.success) {
    throw new Error(res.message || "Login failed");
  }

  Storage.set("access_token", res.access_token);
  Storage.set("refresh_token", res.refresh_token);
  Storage.set("user", JSON.stringify(res.user || {}));

  const role = (res.user && res.user.role) ? String(res.user.role) : "student";
  if (role === "staff") {
    window.location.href = "Staff_Dashboard.html";
    return;
  }
  window.location.href = "Student_Dashboard.html";
}
