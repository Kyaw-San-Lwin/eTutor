function requireAuth() {
  const token = Storage.get("access_token");
  if (!token) {
    window.location.href = "Login.html";
  }
}
