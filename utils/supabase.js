// إعداد الاتصال بـ Supabase
import { createClient } from '@supabase/supabase-js';

const supabaseUrl = 'https://zrwtxvybdxphylsvjopi.supabase.co';
const supabaseKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inpyd3R4dnliZHhwaHlsc3Zqb3BpIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDkyMzM1NzIsImV4cCI6MjA2NDgwOTU3Mn0.QjdaZ5AjDJEgu7rNIY4gnSqzEww0VXJ4DeM3RrykI2s';

export const supabase = createClient(supabaseUrl, supabaseKey);
