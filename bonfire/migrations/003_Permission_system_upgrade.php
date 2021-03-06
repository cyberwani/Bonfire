<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Upgrade Permission system by:
 *	Making the name of the Permission a value in the table rather than a column
 *	Adding a Role/Permissions ref table
 */
class Migration_Permission_system_upgrade extends Migration
{
	/****************************************************************
	 * Table names
	 */
	private $permissions_table = 'permissions';
	private $permissions_old_table = 'permissions_old';
	private $role_permissions_table = 'role_permissions';

	/****************************************************************
	 * Field definitions
	 */
	/**
	 * @var array New fields to add to the Permissions table
	 */
	private $permissions_fields = array(
		"`Site.Signin.Offline` tinyint(1) NOT NULL DEFAULT '0'",
/*		'Site.Signin.Offline' => array(
			'type' => 'TINYINT',
			'constraint' => 1,
			'default' => 0,
		),
 */
	);

	/**
	 * @var array Fields to modify in the Permissions table
	 */
	private $permissions_modify_fields = array(
		'Site.Statistics.View' => array(
			'name' => 'Site.Reports.View',
			'type' => 'TINYINT',
			'constraint' => 1,
			'default' => 0,
		),
	);

	/**
	 * @var array Fields to modify in the Permissions table during Uninstall
	 */
	private $permissions_modify_fields_down = array(
		'Site.Reports.View' => array(
			'name' => 'Site.Statistics.View',
			'type' => 'TINYINT',
			'constraint' => 1,
			'default' => 0,
		),
	);

	/**
	 * @var array Fields to drop from the permissions table
	 */
	private $permissions_drop_fields = array(
		'Site.Appearance.View' => array(
			'type' => 'TINYINT',
			'constraint' => 1,
			'default' => 0,
		),
	);

	/**
	 * @var array Fields for the new Permissions table
	 */
	private $permissions_new_fields = array(
		'permission_id' => array(
			'type' => 'INT',
			'constraint' => 11,
			'null' => FALSE,
			'auto_increment' => TRUE,
		),
		'name' => array(
			'type' => 'VARCHAR',
			'constraint' => 30,
		),
		'description' => array(
			'type' =>'VARCHAR',
			'constraint' => 100,
		),
		'status' => array(
			'type' => 'ENUM',
			'constraint' => "'active','inactive','deleted'",
			'default' => 'active'
		),
	);

	/**
	 * @var array Fields for the role_permissions table
	 */
	private $role_permissions_fields = array(
		'role_id' => array(
			'type' => 'INT',
			'constraint' => 11,
		),
		'permission_id' => array(
			'type' => 'INT',
			'constraint' => 11,
		),
	);

	/****************************************************************
	 * Data for Insert/Update
	 */
	/**
	 * @var array Data to update the permissions table
	 */
	private $permissions_data = array(
		'Site.Signin.Offline' => 1,
	);

	/****************************************************************
	 * Migration methods
	 */
	/**
	 * Install this migration
	 */
	public function up()
	{
		/* Take care of a few preliminaries before updating */
		// Add new Site.Signin.Offline permission
        if ( ! $this->db->field_exists('Site.Signin.Offline', $this->permissions_table))
        {
            $this->dbforge->add_column($this->permissions_table, $this->permissions_fields);
        }
//		$this->db->where('role_id', 1)->update($this->permissions_table, $this->permissions_data);
		$prefix = $this->db->dbprefix;
		$this->db->query("UPDATE {$prefix}permissions SET `Site.Signin.Offline`=1 WHERE `role_id`=1");

		// Rename Site.Statistics.View to Site.Reports.View
        if ($this->db->field_exists('Site.Statistics.View', $this->permissions_table))
        {
//		    $this->dbforge->modify_column($this->permissions_table, $this->permissions_modify_fields);
            $this->db->query("ALTER TABLE {$prefix}permissions CHANGE `Site.Statistics.View` `Site.Reports.View` TINYINT(1) DEFAULT 0 NOT NULL");
        }

		// Remove Site.Appearance.View
        if ($this->db->field_exists('Site.Appearance.View', $this->permissions_table))
        {
/*		    foreach ($this->permissions_drop_fields as $column_name => $column_def)
            {
            	$this->dbforge->drop_column($this->permissions_table, $column_name);
            }
 */
            $this->db->query("ALTER TABLE {$prefix}permissions DROP COLUMN `Site.Appearance.View`");
        }

		/* Do the actual update. */
		// get the current permissions assigned to each role
		$permission_query = $this->db->get($this->permissions_table);

		// get the field names in the current permissions table
		$permissions_fields = $permission_query->list_fields();

		$old_permissions_array = array();
		foreach ($permission_query->result_array() as $row)
		{
			$old_permissions_array[$row['role_id']] = $row;
		}

		// modify the permissions table
		$this->dbforge->rename_table($this->permissions_table, $this->permissions_old_table);

		$this->dbforge->add_field($this->permissions_new_fields);
		$this->dbforge->add_key('permission_id', TRUE);
		$this->dbforge->create_table($this->permissions_table);

		// add records for each of the old permissions
		$old_permissions_records = array();
		foreach ($permissions_fields as $field)
		{
			if ($field != 'role_id' && $field != 'permission_id')
			{
				$old_permissions_records[] = array(
					'name' => $field,
					'description' => '',
				);
			}
		}
		$old_permissions_records[] = array(
			'name' 			=> 'Permissions.Settings.View',
			'description'	=> 'Allow access to view the Permissions menu unders Settings Context'
		);
		$old_permissions_records[] = array(
			'name'			=> 'Permissions.Settings.Manage',
			'description'	=> 'Allow access to manage the Permissions in the system',
		);
		$this->db->insert_batch($this->permissions_table, $old_permissions_records);

		// create the new role_permissions table
		$this->dbforge->add_field($this->role_permissions_fields);
		$this->dbforge->add_key('role_id', TRUE);
		$this->dbforge->add_key('permission_id', TRUE);
		$this->dbforge->create_table($this->role_permissions_table);

		// add records to allow access to the permissions by the roles - adding records to role_permissions
		// get the current list of permissions
		$new_permission_query = $this->db->get($this->permissions_table);
		$role_permissions_records = array();
		// loop through the current permissions
		foreach ($new_permission_query->result_array() as $permission_rec)
		{
			// loop through the old permissions
			foreach ($old_permissions_array as $role_id => $role_permissions)
			{
				// if the role had access to this permission then give it access again
				if (isset($role_permissions[$permission_rec['name']]) && $role_permissions[$permission_rec['name']] == 1)
				{
					$role_permissions_records[] = array(
						'role_id' => $role_id,
						'permission_id' => $permission_rec['permission_id'],
					);
				}

				// specific case for the administrator to get access to - Bonfire.Permissions.Manage
				if ($role_id == 1 && $permission_rec['name'] == 'Bonfire.Permissions.Manage')
				{
					$role_permissions_records[] = array(
						'role_id' => $role_id,
						'permission_id' => $permission_rec['permission_id'],
					);
				}
			}

			// give the administrator access to the new "Permissions" permissions
			if ($permission_rec['name'] == 'Permissions.Settings.View' || $permission_rec['name'] == 'Permissions.Settings.Manage')
			{
				$role_permissions_records[] = array(
					'role_id' => 1,
					'permission_id' => $permission_rec['permission_id'],
				);
			}
		}

		$this->db->insert_batch($this->role_permissions_table, $role_permissions_records);
	}

	/**
	 * Uninstall this migration
	 */
	public function down()
	{
		// Drop our tables
		$this->dbforge->drop_table($this->permissions_table);
		$this->dbforge->drop_table($this->role_permissions_table);

		// Rename the old permissions table
		$this->dbforge->rename_table($this->permissions_old_table, $this->permissions_table);
		// Rename Site.Reports.View to Site.Statistics.View
		$this->dbforge->modify_column($this->permissions_table, $this->permissions_modify_fields_down);
	}
}