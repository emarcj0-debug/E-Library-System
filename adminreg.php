<html>
<head>
<title>Admin Registration</title>
</head>
<center>
<body>
<form action  = 'home.html' method = 'post'>
<table border = 10 style = 'border-color: #daa520; right: 0; height: 100%; width: 100%; position: fixed; top: 0;'> 
<tr>
<th style = 'text-align: right; height: 50;'>
<input type = 'submit' value = 'Log Out' style = 'font-size: 25;' onclick = "location.href = home.html";>
</th>
</tr>
</form>
<form action  = 'adminreg.php' method = 'post'>
<th>
<label style = 'font-size:50; color:#cfb53b; font-weight:bold;'>
Admin Registration
</label>

<br><br>

<input type = 'text' placeholder = 'Account Name' name = 'txtan'required>

<br><br>

Gender:
<input type = 'radio' id = 'lalake' onclick = 'male()'>Male
<input type = 'radio' id = 'babae' onclick = 'female()'>Female

<input type = 'text' name = 'txtgender' id = 'txtgender' required style = 'display: none;'>


<script>
function male()
{
babae.checked = false;
txtgender.value = 'Male';
}

function female()
{
lalake.checked = false;	
txtgender.value = 'Female';
}
</script>

<br><br>

<input type = 'text' placeholder = 'Username' name = 'txtun' required>

<br><br>

<input type = 'password' placeholder = 'Password' name = 'txtpw' required>

<br><br>

<input type = 'submit' value = 'Register' name = 'btnreg'>
</th>
</table>
</form>
</center>
</body>
</html>

<?php

$cn = mysqli_connect('localhost', 'root', '', 'db_library');

if(isset($_POST['btnreg']))
{
$a = $_POST['txtan'];
$b = $_POST['txtgender'];
$c = $_POST['txtun'];
$d = $_POST['txtpw'];

$sql = mysqli_query($cn, "insert into tbl_adminreg (acct_name, gender, username, password) values ('$a', '$b', '$c', '$d')");

echo"<script>
alert('Registration Successful')
</script>";

if(isset($_POST['btnlogin']))

$a = $_POST['txtuser'];
$b = $_POST['txtpass'];

$sql = mysqli_query($cn, "select * from tbl_adminreg where username = '$a' and password = '$b'");

$login = mysqli_num_rows($sql);

if($login >= 1)
{
echo"<script>
alert('Login Successful')
window.location.href = 'adminreg.php';
</script>";
}
else
{
echo"<script>
alert('Login Failed')
</script>";
}	
}

?>