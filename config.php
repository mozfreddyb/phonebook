<?php

define('LDAP_HOST', 'pm-ns.mozilla.org');

/*************************************************************************/

class MozillaAuthAdapter extends AuthAdapter {
  public function check_valid_user($user) {
    return preg_match('/^[a-z.]+@(.+?)\.(.+)$|^[a-z]+$/', $user);
  }

  public function user_to_dn($user) {
    if (preg_match('/^[a-z]+$/', $user)) {
      return "mail=$user@mozilla.com,o=com,dc=mozilla";
    }
    preg_match('/^[a-z.]+@(.+?)\.(.+)$/', $user, $m);
    if ($m[1] == "mozilla" && $m[2] == "com") {
      // pass
    } elseif ($m[1] == "mozilla-japan" && $m[2] == "org") {
      $m[1] = "mozilla";
      $m[2] = "com";
    } elseif (strpos($m[1], "mozilla") === 0 && $m[2] == "org") {
      $m[1] = "mozilla";
      $m[2] = "org";
    } else {
      $m[1] = "mozilla";
      $m[2] = "net";
    }
    return "mail=$user,o={$m[2]},dc={$m[1]}";
  }

  public function dn_to_email($dn) {
    if (preg_match("/mail=(\w+@.+),o=/", $dn, $m)) {
      return $m[1];
    }
    return NULL;
  }

  public function email_to_dn($ldapconn, $email) {
    $user_s = ldap_search($ldapconn, "dc=mozilla", "mail=" . $email);
    $user_s_r = ldap_get_entries($ldapconn, $user_s);
    if ($user_s_r['count'] != 1) {
      die("Multiple DNs match email.");
    }
    return $user_s_r[0]['dn'];
  }

  // The logic here is that failure to find out who has permissions to edit
  // someone else's entry implies that you aren't one of them.
  public function is_phonebook_admin($ldapconn, $dn) {
    $search = ldap_list(
      $ldapconn,
      "ou=groups, dc=mozilla", "(&(member=$dn)(cn=phonebook_admin))",
      array("cn")
    );
    $results = ldap_get_entries($ldapconn, $search);
    return $results["count"];
  }
}

/*************************************************************************/

class MozillaEditingAdapter extends EditingAdapter {
  public function cook_incoming(&$new_user_data, $is_admin) {
    foreach (array("title", "telephoneNumber", "description", "manager",
                  "other", "mobile", "im", "emailAlias", "bugzillaEmail")
            as $attribute) {
      if (isset($new_user_data[$attribute])) {
        $new_user_data[$attribute] = $this->box($new_user_data[$attribute]);
      }
    }

    if ($_POST["office_city"] == "Other") {
      $_POST["office_city"] = $_POST["office_city_name"];
    }
    $new_user_data['physicalDeliveryOfficeName'] = $this->box(array(implode(':::', array($_POST['office_city'], $_POST['office_country']))));

    if ($is_admin) {
      $new_user_data['employeeType'] = $this->box(
        $this->get_status($_POST['org_code'], $_POST['employee_type_code'])
      );
      if (isset($_POST['is_manager'])) {
        fb("is_manager: ". $_POST['is_manager']);
        $new_user_data['isManager'] = $this->box($this->ldap_bool($_POST['is_manager']));
      }
    }
  }

  public function ldap_bool($boolean) {
    return $boolean ? "TRUE" : "FALSE";
  }

  // Used to create LDAP data structures
  public function box($element) {
    if (empty($element[0])) {
      return array();
    }
    return $element;
  }

  // Facilitates in creating user
  public function get_status($current_org, $current_emp_type) {
    if ($current_emp_type == 'D' ||
        $current_org == 'D') {
      return "DISABLED";
    } else {
      return $current_org . $current_emp_type;
    }
  }

  public function clean_userdata($user_data) {
    global $editable_fields;
    foreach ($editable_fields as $field) {
      $field = strtolower($field);
      if (!isset($user_data[$field])) {
        $user_data[$field] = array('count' => 0, '');
      }
    }
    return $user_data;
  }

  public function clean_boolean($value) {
    return $value ? 'True' : 'False';
  }
}

/*************************************************************************/

class MozillaSearchAdapter extends SearchAdapter {
  public $fields = array(
    'cn', 'title', 'telephoneNumber', 'mobile', 'description', 'manager',
    'other', 'im', 'mail', 'emailAlias', 'physicalDeliveryOfficeName',
    'employeeType', 'isManager', 'bugzillaEmail'
  );
  public $conf = array(
    "ldap_sort_order" => "sn"
  );

  public function search_users($search) {
    if ($search != "random") {
      return $this->_search_users($search);
    }
    $entries = $this->_search_users('*');
    return array($entries[mt_rand(0, count($entries) - 1)]);
  }

  public function _search_users($search) {
    $filter = ($search == '*') ? 'objectClass=mozComPerson' : "(&(|(cn=*$search*)(mail=*$search*)(im=*$search*))(objectClass=mozComPerson))";
    return $this->query_users($filter, 'dc=mozilla', $this->fields);
  }

  public function preprocess_entry(&$entry) {
    if (preg_match("/mail=(\w+@.+),o=/", $entry["dn"], $m)) {
      $entry["picture"] = BASEPATH ."pic.php?mail=". $m[1];
    }
  }

  public function list_everyone() {
    return $this->query_users(
      "objectClass=mozComPerson", "o=com,dc=mozilla", array("dn")
    );
  }
}

/*************************************************************************/

class MozillaTreeAdapter extends TreeAdapter {
  public $conf = array(
    "ldap_search_base" => "o=com, dc=mozilla",
    "ldap_search_filter" => "mail=*",
    "ldap_search_attributes" => array(
      "sn", "cn", "manager", "title", "mail", "employeeType"
    )
  );
  public $roots = array(
    "mitchell@mozilla.com", "lilly@mozilla.com", "dascher@mozilla.com"
  );

  public function process_entry($person) {
    return array(
      "title" => !empty($person["title"][0]) ? $person["title"][0] : NULL,
      "name" => !empty($person["cn"][0]) ? $person["cn"][0] : NULL,
      "sn" => !empty($person["sn"][0]) ? $person["sn"][0] : NULL,
      "disabled" => isset($person["employeetype"]) ?
                      strpos($person["employeetype"][0], 'D') !== FALSE:
                      FALSE
    );
  }

  public function format_item(&$everyone, $email, $leaf=FALSE) {
    $email = htmlspecialchars($email);
    $id = str_replace('@', "-at-", $email);
    $name = htmlspecialchars($everyone[$email]["name"]);
    $title = htmlspecialchars($everyone[$email]["title"]);
    $leaf = $leaf ? " leaf" : '';
    $disabled = $everyone[$email]["disabled"] ? " disabled" : '';
    return "<li id=\"$id\" class=\"hr-node expanded$leaf$disabled\">".
             "<a href=\"#search/$email\" class=\"hr-link\">$name</a> ".
             "<span class=\"title\">$title</span>".
           "</li>";
  }

  public function sort_items($a, $b) {
    global $everyone;
    global $people;
    list($x, $y) = array(empty($people[$a]), empty($people[$b]));
    if (($x && $y) || (!$x && !$y)) {
      return strcmp($everyone[$a]["sn"], $everyone[$b]["sn"]);
    }
    if (!$x && $y) {
      return 1;
    }
    if ($y && !$x) {
      return -1;
    }
  }
}
