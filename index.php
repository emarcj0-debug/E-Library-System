<html>
<head>
<title>index</title>
</head>
<body>

<?php

$cn = mysqli_connect('localhost', 'root', '', 'db_library');

if(isset($_POST['btnlogin']))
{
$a = $_POST['txtuser'];
$b = $_POST['txtpass'];

$sql = mysqli_query($cn, "select * from tbl_login where username = '$a' and password = '$b'");

$login = mysqli_num_rows($sql);

if($login >= 1)
{
echo"<script>
alert('Login Successful')
window.location.href = 'home.html';
</script>";
}
else
{
echo"<script>
alert('Login Failed')
window.location.href = 'login.html';
</script>";
}	
}

?>

</body>
</html>