<?PHP

// Year-End Inventory Check Program
// written by Quentin Skousen
// qskousen.info
// Copyright 2012 Quentin Skousen

require_once('class.my_db.php');	// custom DB class extends mysqli

// config settings

$config['table_name'] = 'invcheck';
if (!isset($_SERVER['PLATFORM']) || $_SERVER['PLATFORM'] != 'PAGODA') {
	// local server
	$config['db_host'] = '127.0.0.1';
	$config['db_user'] = 'root';
	$config['db_pass'] = '';
	$config['db_name'] = 'invcheck';
	$config['db_port'] = '3306';
}
else {
	// running on pagodabox
	$config['db_host'] = $_SERVER["DB1_HOST"];
	$config['db_user'] = $_SERVER["DB1_USER"];
	$config['db_pass'] = $_SERVER["DB1_PASS"];
	$config['db_name'] = $_SERVER["DB1_NAME"];
	$config['db_port'] = $_SERVER["DB1_PORT"];
}

// start a session so we can run the db and table check just once per session
session_start();

// Create a DB connection
$db = new my_db($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name'], $config['db_port']);
if ($db->connect_error) {
	die('ERROR: Could not connect to DB! '.$db->connect_errno.' '.$db->connect_error);
}
$config['db'] = $db;

$check = true; // default to true
if (!isset($_SESSION['db_checked'])) $check = db_check($config);
if (!$check || !isset($_SESSION['imported'])) {
	// table was created, need to import data
	// fake some GET data to force an import
	$_GET['action'] = 'import';
	// on successful import, needs to set $_SESSION['imported'] = true;
}

if (isset($_GET['action']) && trim($_GET['action']) != '') {
	switch ($_GET['action']) {
		case 'add':
			add_item($config);
			break;
		case 'edit':
			echo 'edit';
			break;
		case 'clear':
			drop_table($config);
			break;
		case 'clearconfirm';
			clearconfirm();
			break;
		case 'list':
			echo 'list';
			break;
		case 'import':
			import($config);
			break;
		default:
			add_item($config);
			break;
	}
}
else {
	// no GET - default page
	add_item($config);
}

function add_item($config) {
	$submitted = false;
	if (isset($_GET['action'])) {
		unset($_GET['action']);  // so it doesn't submit again
		if (((isset($_GET['barcode']) && trim($_GET['barcode']) != '') || (isset($_GET['sku']) && trim($_GET['sku']) != '')) && isset($_GET['quantity']) && trim($_GET['quantity']) != '') {
			$submitted = true;
			if (isset($_GET['sku']) && trim($_GET['sku']) != '') {
				$input = $_GET['sku'];
				$method = 'sku';
			}
			unset($_GET['sku']);
			if (isset($_GET['barcode']) && trim($_GET['barcode']) != '') {
				$input = $_GET['barcode'];
				$method = 'barcode';
			}
			unset($_GET['barcode']);
			$quantity = (int) $_GET['quantity'];
			unset($_GET['quantity']);
			// $_SESSION['message'][] = 'Input: '.$input.' Method: '.$method.' Quantity: '.$quantity;
		}
		else {
			if ((!isset($_GET['barcode']) || trim($_GET['barcode'] == '')) && (!isset($_GET['sku']) || trim($_GET['sku'] == ''))) {
				$_SESSION['message'][] = 'You must enter a barcode or an SKU.';
				$barcode_error = true;
				$sku_error = true;
			}
			if (!isset($_GET['quantity']) || trim($_GET['quantity']) == '') {
				$_SESSION['message'][] = 'You must enter a quantity.';
				$quantity_error = true;
			}
		}
	}
	if ($submitted) {
		// has been submitted and has data, process
		
		$result = db_set_quantity($config, $input, $quantity, $method);
		
		if ($result !== false) {
			$compare = compare_item_quantity($config, $result);
			$_SESSION['message'][] = $quantity.' items of '.$input.' added.';
			if ($compare > 0) $_SESSION['message'][] = ' There are '.$compare.' more items in the database than you have scanned.';
			else if ($compare < 0) $_SESSION['message'][] = ' There are '.abs($compare).' less items in the database than you have scanned.';
			else if ($compare === 0) $_SESSION['message'][] = ' This quantity matches what is stored in the database.';
			else $_SESSION['message'][] = ' WARNING: Compare error!';  // should never display
		}
		// if NOT result, a message will be set
		
		add_item($config);
	}
	else {
		// not submitted or empty values
		draw_header('Scan Item');
		if (isset($_SESSION['message'])) message($_SESSION['message']);
		echo '<form action="/" method="GET"><table>';
		echo '<input type="hidden" name="action" value="new" />';
		echo '<tr><td><label for="barcode">Barcode:</label></td>';
		echo '<td><input name="barcode" type="text" size="30"'.(@$barcode_error ? 'class="error" ' : '').' '.(isset($_GET['barcode']) ? 'value="'.$_GET['barcode'].'" ' : '').' /></td></tr>';
		echo '<tr><td><label for="sku">SKU:</label></td>';
		echo '<td><input name="sku" type="text" size="30"'.(@$sku_error ? 'class="error" ' : '').' '.(isset($_GET['sku']) ? 'value="'.$_GET['sku'].'" ' : '').' /></td></tr>';
		echo '<tr><td><label for="quantity">Quantity:</label></td>';
		echo '<td><input name="quantity" type="text" size="5"'.(@$quantity_error ? 'class="error" ' : '').' '.(isset($_GET['quantity']) ? 'value="'.$_GET['quantity'].'" ' : 'value="1"').' /></td></tr>';
		echo '</table><br /><input type="submit" /></form>';
		draw_buttons();
		draw_footer();
	}
}

function import($config) {
	$db = $config['db'];
	if (isset($_POST['MAX_FILE_SIZE'])) {
		// process input file
		if (isset($_FILES['userfile'])) {
			$file = $_FILES['userfile']['tmp_name'];
		}
		else {
			$_SESSION['message'][] = 'File upload not found!';
			unset($_POST['MAX_FILE_SIZE']);
			import($config);
		}
		if (($handle = fopen($file, "r")) !== FALSE) {
			$error = false;
			if (($header = fgetcsv($handle, 1000, ",")) == false) {
				$_SESSION['message'][] = 'Could not read from CSV!';
				unset($_POST['MAX_FILE_SIZE']);
				import($config);
			}
			$expected = array('Barcode','SKU','Qty.','Description');
			$head = array();
			
			foreach ($expected as $expect) {
				if (($head[$expect] = array_search($expect,$header)) === false) {
					$_SESSION['message'][] = 'ERROR: Header column '.$expect.' not found!';
					$error = true;
				}
			}
			if (!$error) {
				// everything is good to go - import!!
				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
					$values = array();
					foreach ($expected as $expect) {
						$values[] = '\''.$db->real_escape_string(trim($data[$head[$expect]])).'\'';
					}
					$values = implode(',',$values);
					$query = 'INSERT INTO '.$config['table_name'].' (`barcode`, `sku`, `quantity_import`, `desc`) VALUES ('.$values.');';
					$result = $db->query($query);
					if (!$result) {
						$_SESSION['message'][] = 'ERROR: Insert failed: '.$query.' '.$db->error;
					}
				}
				$_SESSION['imported'] = true;
			}
			else {
				$_SESSION['message'][] = 'ERROR: one or more problems found!';
				unset($_POST['MAX_FILE_SIZE']);
				import($config);
			}
		}
		else {
			$_SESSION['message'][] = 'ERROR: Could not open file!';
			unset($_POST['MAX_FILE_SIZE']);
			import($config);
		}
		unset($_GET);
		add_item($config);
	}
	else {
		draw_header('Import File');
		if (isset($_SESSION['message'])) message($_SESSION['message']);
		echo 'Import your inventory as a CSV file. Please include a header row. The file should contain the following fields, set in the header row:<br />';
		echo '<ul><li>Barcode</li><li>SKU</li><li>Qty.</li><li>Description</li></ul>';
		echo '<br />Capitalization is important for these fields.<br />';
		echo '<form enctype="multipart/form-data" action="/?action=import" method="POST">
				<input type="hidden" name="MAX_FILE_SIZE" value="2097152000" />
				Select a file: <input name="userfile" type="file" /><br />
				<input type="submit" value="Send File" />
			</form>';
		draw_footer();
	}
}

function clearconfirm() {
	draw_header('Confirm Clear');
	echo '<form action="/" method="GET">';
	echo '<input type="hidden" name="action" value="clear" />';
	echo 'Are you sure you want to empty the database?<br />';
	echo '<input type="submit" value="Confirm" />';
	echo '<a href="/"><button>Cancel</button></a>';
	echo '</form>';
	draw_footer();
}

function message($message) {
	if (is_array($message)) {
		foreach($message as $m) {
			echo '<span class="notice">'.$m.'</span><br />';
		}
	}
	else {
		echo '<span class="notice">'.$message.'</span><br />';
	}
	unset($_SESSION['message']);
}

function draw_buttons() {
	echo '<div id="buttons">';
	echo '<a href="/"><button>Add</button></a>';
	echo '<a href="/?action=edit"><button>Edit</button></a>';
	echo '<a href="/?action=list"><button>List All</button></a>';
	echo '<a href="/?action=clearconfirm"><button>Clear Database</button></a>';
	echo '</div>';
}

function db_set_quantity($config, $input, $quantity, $method) {
	$db = $config['db'];
	$query = 'SELECT id FROM '.$config['table_name'].' WHERE '.$method.' = \''.$input.'\';';
	// $_SESSION['message'][] = 'Query: '.$query;
	$id = $db->lookup_singular($query);
	if ($id !== null) {
		if ($db->query('UPDATE '.$config['table_name'].' SET quantity_scan = '.$quantity.' WHERE id = '.$id)) {
			return $id;
		}
		else $_SESSION['message'][] = 'ERROR: quantity_scan could not be updated!';
	}
	else $_SESSION['message'][] = 'ERROR: Could not find item '.$input;
	return false;
}

function compare_item_quantity($config, $item) {
	$db = $config['db'];
	
	$query = 'SELECT quantity_import, quantity_scan FROM '.$config['table_name'].' WHERE id = '.$item.';';
	// $_SESSION['message'][] = 'Query: '.$query;
	$result = $db->query($query);
	if (!$result) {
		$_SESSION['message'][] = 'Could not find quantities! '.$db->error;
		return false;
	}
	$result_assoc = $result->fetch_assoc();
	
	return $result_assoc['quantity_import'] - $result_assoc['quantity_scan'];
}	

// DEPRECIATED with new mysqli extended class my_db
// function db_connect($config) {
	// conect to DB and return DB object, PDO style
	// $db = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name'], $config['db_port']);
	// if ($db->connect_error) {
		// die('ERROR: Could not connect to DB! '.$db->connect_errno.' '.$db->connect_error);
	// }
	// return $db;
// }

function db_check($config) {
	$db = $config['db'];
	$table_exists = $db->query("SHOW TABLES LIKE '".$config['table_name']."'")->num_rows > 0;
	if (!$table_exists) {
		$result = $db->query("CREATE TABLE `".$config['table_name']."` (
				`id` INT(100) UNSIGNED NOT NULL AUTO_INCREMENT,
				`created_timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`barcode` VARCHAR(20) NULL DEFAULT NULL,
				`sku` VARCHAR(50) NULL DEFAULT NULL,
				`desc` VARCHAR(200) NULL DEFAULT NULL,
				`quantity_import` INT NOT NULL DEFAULT '0',
				`quantity_scan` INT NOT NULL DEFAULT '0',
				`ignore` INT NULL DEFAULT NULL,
				PRIMARY KEY (`id`)
			)");
		if (!$result) die ('ERROR: Could not create table '.$config['table_import']);
		$_SESSION['db_checked'] = true;
		return false;  // table did not exist but was created (import needed)
	}
	else {
		$_SESSION['db_checked'] = true;
		// table already exists, assume data has been imported
		$_SESSION['imported'] = true;
		return true;  // table exists already
	}
}

function drop_table($config) {
	$db = $config['db'];
	$db->query('DROP TABLE '.$config['table_name'].';');
	unset($_SESSION['db_checked']);
	unset($_SESSION['imported']);
	db_check($config);
	import($config);
}

function draw_header($title) {
	echo '<html>
	<head>
	<title>Inventory Check - '.$title.'</title>
	<link rel="stylesheet" type="text/css" href="default.css" />
	</head>
	<body>
	<h1>'.$title.'</h1><br />';
}

function draw_footer() {
	echo '<div id="footer"><b>Inventory Check</b> &copy; <a href="http://www.qskousen.info">Quentin Skousen</a> 2012.</div>
	</body>
	</html>';
}

function logdebug($string) {
	file_put_contents('debug.txt', $string, FILE_APPEND);
}