// public/login.js
document.getElementById("loginForm").addEventListener("submit", async function (e) {
  e.preventDefault();

  const email = document.getElementById("email").value;
  const password = document.getElementById("password").value;

  const response = await fetch("/api/login", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ email, password }),
  });

  const result = await response.json();

  if (response.ok) {
    window.location.href = "dashboard.html";
    console.log(result.user);
  } else {
    alert(result.error || "فشل تسجيل الدخول");
  }
});

// جلب وعرض قائمة المدراء من Supabase
async function fetchAndShowAdmins() {
  try {
    const supabaseUrl = "https://zrwtxvybdxphylsvjopi.supabase.co";
    const supabaseKey = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inpyd3R4dnliZHhwaHlsc3Zqb3BpIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDkyMzM1NzIsImV4cCI6MjA2NDgwOTU3Mn0.QjdaZ5AjDJEgu7rNIY4gnSqzEww0VXJ4DeM3RrykI2s";
    const supabase = window.supabase.createClient(supabaseUrl, supabaseKey);
    const { data: admins, error } = await supabase
      .from("admins")
      .select("email")
      .order("id", { ascending: true });
    const ul = document.getElementById("admins-ul");
    ul.innerHTML = "";
    if (admins && admins.length > 0) {
      admins.forEach((admin) => {
        const li = document.createElement("li");
        li.style.padding = "0.5rem 0";
        li.textContent = admin.email;
        ul.appendChild(li);
      });
    } else {
      ul.innerHTML = "<li>لا يوجد مدراء مسجلين.</li>";
    }
  } catch (err) {
    document.getElementById("admins-ul").innerHTML = "<li>تعذر جلب قائمة المدراء.</li>";
  }
}
fetchAndShowAdmins();
