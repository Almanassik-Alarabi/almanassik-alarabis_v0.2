// خادم Express.js يدعم static و API
import express from "express";
import path from "path";
import { fileURLToPath } from "url";
import bodyParser from "body-parser";
import { supabase } from "./utils/supabase.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = 3000;

app.use(bodyParser.json());
app.use(express.static(path.join(__dirname, "public")));
app.use('/admin', express.static(path.join(__dirname, 'admin')));

app.post("/api/login", async (req, res) => {
  const { email, password } = req.body;
  try {
    // استخدام مصادقة Supabase مباشرة
    const { data, error } = await supabase.auth.signInWithPassword({
      email,
      password,
    });
    if (error || !data || !data.user) {
      return res.status(401).json({ error: "البريد الإلكتروني أو كلمة المرور غير صحيحة" });
    }
    // نجاح الدخول
    res.status(200).json({
      user: {
        id: data.user.id,
        email: data.user.email,
      },
    });
  } catch (error) {
    res.status(500).json({ error: error.message });
  }
});

// إضافة route لفحص صحة الخادم
app.get("/api/ping", (req, res) => {
  res.json({ status: "ok" });
});

app.listen(PORT, () => {
  console.log(`Server running on http://localhost:${PORT}`);
});
