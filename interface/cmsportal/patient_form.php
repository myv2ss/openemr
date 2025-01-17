<?php
/**
 * Patient matching and selection for the WordPress Patient Portal.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2014 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/options.inc.php");
require_once("portal.inc.php");

use OpenEMR\Core\Header;

$postid = intval($_REQUEST['postid']);
$ptid   = intval($_REQUEST['ptid'  ]);

if ($_POST['bn_save']) {
    $newdata = array();
    $newdata['patient_data' ] = array();
    $newdata['employer_data'] = array();
    $ptid = intval($_POST['ptid']);
  // Note we are careful to maintain cmsportal_login even if the layout has it
  // configured as unused.
    $fres = sqlStatement("SELECT * FROM layout_options WHERE " .
    "form_id = 'DEM' AND field_id != '' AND (uor > 0 OR field_id = 'cmsportal_login') " .
    "ORDER BY group_id, seq");
    while ($frow = sqlFetchArray($fres)) {
        $data_type = $frow['data_type'];
        $field_id  = $frow['field_id'];
        $table = 'patient_data';
        if (isset($_POST["form_$field_id"])) {
            $newdata[$table][$field_id] = get_layout_form_value($frow);
        }
    }

    if (empty($ptid)) {
        $tmp = sqlQuery("SELECT MAX(pid)+1 AS pid FROM patient_data");
        $ptid = empty($tmp['pid']) ? 1 : intval($tmp['pid']);
        if (empty($newdata['patient_data']['pubpid'])) {
            // pubpid for new patient defaults to pid.
            $newdata['patient_data']['pubpid'] = "$ptid";
        }

        updatePatientData($ptid, $newdata['patient_data' ], true);
        updateEmployerData($ptid, $newdata['employer_data'], true);
        newHistoryData($ptid);
    } else {
        $newdata['patient_data']['id'] = $_POST['db_id'];
        updatePatientData($ptid, $newdata['patient_data']);
    }

  // Finally, delete the request from the portal.
    $result = cms_portal_call(array('action' => 'delpost', 'postid' => $postid));
    if ($result['errmsg']) {
        die(text($result['errmsg']));
    }

    echo "<html><body><script language='JavaScript'>\n";
    echo "if (top.restoreSession) top.restoreSession(); else opener.top.restoreSession();\n";
    echo "document.location.href = 'list_requests.php';\n";
    echo "</script></body></html>\n";
    exit();
}

$db_id  = 0;
if ($ptid) {
    $ptrow = getPatientData($ptid, "*");
    $db_id = $ptrow['id'];
}

if ($postid) {
    $result = cms_portal_call(array('action' => 'getpost', 'postid' => $postid));
    if ($result['errmsg']) {
        die(text($result['errmsg']));
    }
}
?>
<html>
<head>
<?php Header::setupHeader(['no_bootstrap', 'no_fontawesome', 'no_dialog', 'datetime-picker']); ?>

<style>

tr.head   { font-size:10pt; background-color:#cccccc; text-align:center; }
tr.detail { font-size:10pt; background-color:#ddddff; }
td input  { background-color:transparent; }

</style>

<script language="JavaScript">

var mypcc = '<?php echo $GLOBALS['phone_country_code'] ?>';

function myRestoreSession() {
 if (top.restoreSession) top.restoreSession(); else opener.top.restoreSession();
 return true;
}

// This capitalizes the first letter of each word in the passed input
// element.  It also strips out extraneous spaces.
// Copied from demographics_full.php.
function capitalizeMe(elem) {
 var a = elem.value.split(' ');
 var s = '';
 for(var i = 0; i < a.length; ++i) {
  if (a[i].length > 0) {
   if (s.length > 0) s += ' ';
   s += a[i].charAt(0).toUpperCase() + a[i].substring(1);
  }
 }
 elem.value = s;
}

// Generates and returns a random 6-character password.
//
function randompass() {
 var newpass = '';
 var newchar = '';
 while (newpass.length < 6) {
  var r = Math.floor(Math.random() * 33); // for 2-9 and a-y
  if (r > 7) {
   newchar = String.fromCharCode('a'.charCodeAt(0) + r - 8);
   if (newchar == 'l') newchar = 'z';
  } else {
   newchar = String.fromCharCode('2'.charCodeAt(0) + r);
  }
  newpass += newchar;
 }
 var e = document.forms[0].form_cmsportal_login_pass;
 if (e) e.value = newpass;
}

// If needed, this creates the new patient in the CMS. It executes as an AJAX script
// in case it doesn't work and a correction is needed before submitting the form.
//
function validate() {
 var f = document.forms[0];
 var errmsg = '';
 myRestoreSession();
 if (f.form_cmsportal_login_pass) {
  var login = encodeURIComponent(f.form_cmsportal_login.value);
  var pass  = encodeURIComponent(f.form_cmsportal_login_pass.value);
  var email = encodeURIComponent(f.form_email.value);
  if (login) {
   if (!pass) {
    alert('<?php echo xls('Portal password is missing'); ?>');
    return false;
   }
   if (!email) {
    alert('<?php echo xls('Email address is missing'); ?>');
    return false;
   }
   // Need a *synchronous* ajax request here. Successful updating of the portal
   // is required before we can submit the form.
   $.ajax({
    type: "GET",
    dataType: "text",
    url: 'patient_form_ajax.php?login=' + login + '&pass=' + pass + '&email=' + email,
    async: false,
    success: function(data) {
     if (data) {
      alert(data);
      errmsg = data;
     }
    }
   });
  }
 }
 if (errmsg) return false;
 return true;
}

$(function() {
    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
    });
    $('.datetimepicker').datetimepicker({
        <?php $datetimepicker_timepicker = true; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
    });
});

</script>
</head>

<body class="body_top">
<center>

<form method='post' action='patient_form.php' onsubmit='return validate()'>

<input type='hidden' name='db_id'  value="<?php echo attr($db_id);  ?>" />
<input type='hidden' name='ptid'   value="<?php echo attr($ptid);   ?>" />
<input type='hidden' name='postid' value="<?php echo attr($postid); ?>" />

<table width='100%' cellpadding='1' cellspacing='2'>
 <tr class='head'>
  <th align='left'><?php echo xlt('Field'); ?></th>
  <th align='left'><?php echo xlt('Current Value'); ?></th>
  <th align='left'><?php echo xlt('New Value'); ?></th>
 </tr>

<?php
$lores = sqlStatement(
    "SELECT * FROM layout_options " .
    "WHERE form_id = ? AND uor > 0 ORDER BY group_id, seq",
    array('DEM')
);

// Will be used to indicate if this user does not yet have a portal login.
$portal_registration_needed = false;

while ($lorow = sqlFetchArray($lores)) {
    $data_type  = $lorow['data_type'];
    $field_id   = $lorow['field_id'];
  // We deal with this one at the end.
    if ($field_id == 'cmsportal_login') {
        continue;
    }

  // Flamingo translates field names to lower case so we have to match with those.
    $reskey = $field_id;
    foreach ($result['fields'] as $key => $dummy) {
        if (strcasecmp($key, $field_id) == 0) {
            $reskey = $key;
        }
    }

  // Generate form fields for items that are either from the WordPress form
  // or are mandatory for a new patient.
    if (isset($result['fields'][$reskey]) || ($lorow['uor'] > 1 && $ptid == 0)) {
        $list_id = $lorow['list_id'];
        $field_title = $lorow['title'];
        if ($field_title === '') {
            $field_title = '(' . $field_id . ')';
        }

        $currvalue  = '';
        if (isset($ptrow[$field_id])) {
            $currvalue = $ptrow[$field_id];
        }

        /*****************************************************************
      $newvalue = '';
      if (isset($result['fields'][$reskey])) $newvalue = $result['fields'][$reskey];
      //// Zero-length input means nothing will change.
      // if ($newvalue === '') $newvalue = $currvalue;
      // $newvalue = trim($newvalue);
      $newvalue = cms_field_to_lbf($newvalue, $data_type, $field_id);
        *****************************************************************/
        $newvalue = cms_field_to_lbf($data_type, $reskey, $result['fields']);

        echo " <tr class='detail'>\n";
        echo "  <td class='bold'>" . text($field_title) . "</td>\n";
        echo "  <td>" . generate_display_field($lorow, $currvalue) . "</td>\n";
        echo "  <td>";
        generate_form_field($lorow, $newvalue);
        echo "</td>\n";
        echo " </tr>\n";
    }
}

$field_id = 'cmsportal_login';
if (empty($ptrow[$field_id])) {
    if ($result['post']['user'] !== '') {
        // Registered in portal but still need to record that in openemr.
        echo "</table>\n";
        echo "<input type='hidden' name='form_$field_id' value='" . attr($result['post']['user']) . "' />\n";
    } else {
        // Portal registration is needed.
        $newvalue = isset($result['fields']['email']) ? trim($result['fields']['email']) : '';
        echo " <tr class='detail'>\n";
        echo "  <td class='bold' style='color:red;'>" . xlt('New Portal Login') . "</td>\n";
        echo "  <td>&nbsp;</td>\n";
        echo "  <td>";
        echo "<input type='text' name='form_$field_id' size='10' maxlength='60' value='" . attr($newvalue) . "' />";
        echo "&nbsp;&nbsp;" . xlt('Password') . ": ";
        echo "<input type='text' name='form_" . attr($field_id) . "_pass' size='10' maxlength='60' />";
        echo "<input type='button' value='" . xla('Generate') . "' onclick='randompass()' />";
        echo "</td>\n";
        echo " </tr>\n";
        echo "</table>\n";
    }
} else {
  // Portal login name is already in openemr.
    echo "</table>\n";
}
?>

<p>
<input type='submit' name='bn_save' value='<?php echo xla('Save and Delete Request'); ?>' />
&nbsp;
<input type='button' value='<?php echo xla('Back'); ?>' onclick="window.history.back()" />
<!-- Was: onclick="myRestoreSession();location='list_requests.php'" -->
</p>

</form>

<script language="JavaScript">

// hard code validation for old validation, in the new validation possible to add match rules
<?php if ($GLOBALS['new_validate'] == 0) { ?>
// Fix inconsistently formatted phone numbers from the database.
var f = document.forms[0];
if (f.form_phone_contact) phonekeyup(f.form_phone_contact,mypcc);
if (f.form_phone_home   ) phonekeyup(f.form_phone_home   ,mypcc);
if (f.form_phone_biz    ) phonekeyup(f.form_phone_biz    ,mypcc);
if (f.form_phone_cell   ) phonekeyup(f.form_phone_cell   ,mypcc);

<?php }?>

randompass();

// This is a by-product of generate_form_field().
<?php echo $date_init; ?>

</script>

<!-- include support for the list-add selectbox feature -->
<?php include $GLOBALS['fileroot'] . "/library/options_listadd.inc"; ?>

</center>
</body>
</html>

