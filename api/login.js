import bcrypt from "bcryptjs";
import { createClient } from "@supabase/supabase-js";

const supabaseUrl = "https://zrwtxvybdxphylsvjopi.supabase.co";
const supabaseKey =
  "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inpyd3R4dnliZHhwaHlsc3Zqb3BpIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDkyMzM1NzIsImV4cCI6MjA2NDgwOTU3Mn0.QjdaZ5AjDJEgu7rNIY4gnSqzEww0VXJ4DeM3RrykI2s";
const supabase = createClient(supabaseUrl, supabaseKey);

export default async function handler(req, res) {
  if (req.method !== "POST") {
    return res.status(405).json({ error: "Method not allowed" });
  }

  const { email, password } = req.body;

  // جلب المدير عبر البريد الإلكتروني
  const { data: admin, error } = await supabase
    .from("admins")
    .select("email, mot_de_passe")
    .eq("email", email)
    .single();

  if (error || !admin) {
    return res.status(401).json({ success: false, message: "User not found" });
  }

  // تحقق من كلمة المرور bcrypt
  const passwordMatch = await bcrypt.compare(password, admin.mot_de_passe);

  if (passwordMatch) {
    return res.status(200).json({ success: true });
  } else {
    return res
      .status(401)
      .json({ success: false, message: "Invalid password" });
  }
}
