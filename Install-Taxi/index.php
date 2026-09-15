<?php
// Корень домена сервера такси: перенаправляем на админку.
// Без этого файла Apache отдаёт 403 (нет DirectoryIndex).
header('Location: admin/login.php');
