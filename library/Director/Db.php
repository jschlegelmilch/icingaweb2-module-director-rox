<?php

namespace Icinga\Module\Director;

use Icinga\Data\Db\DbConnection;

class Db extends DbConnection
{
    protected $modules = array();

    protected static $zoneCache;

    protected static $commandCache;

    protected function db()
    {
        return $this->getDbAdapter();
    }

    public function fetchActivityLogEntryById($id)
    {
        $sql = 'SELECT * FROM director_activity_log WHERE id = ' . (int) $id;

        return $this->db()->fetchRow($sql);
    }

    public function fetchActivityLogEntry($checksum)
    {
        if ($this->getDbType() === 'pgsql') {
            $checksum = new \Zend_Db_Expr("\\x" . bin2hex($checksum));
        }

        $sql = 'SELECT * FROM director_activity_log WHERE checksum = ?';
        $ret = $this->db()->fetchRow($sql, $checksum);

        if (is_resource($ret->checksum)) {
            $ret->checksum = stream_get_contents($ret->checksum);
        }

        if (is_resource($ret->parent_checksum)) {
            $ret->checksum = stream_get_contents($ret->parent_checksum);
        }

        return $ret;
    }

    public function getLastActivityChecksum()
    {
        if ($this->getDbType() === 'pgsql') {
            $select = "SELECT checksum FROM (SELECT * FROM (SELECT 1 AS pos, LOWER(ENCODE(checksum, 'hex')) AS checksum FROM director_activity_log ORDER BY change_time DESC LIMIT 1) a UNION SELECT 2 AS pos, '' AS checksum) u ORDER BY pos LIMIT 1";
        } else {
            $select = "SELECT checksum FROM (SELECT * FROM (SELECT 1 AS pos, LOWER(HEX(checksum)) AS checksum FROM director_activity_log ORDER BY change_time DESC LIMIT 1) a UNION SELECT 2 AS pos, '' AS checksum) u ORDER BY pos LIMIT 1";
        }

        return $this->db()->fetchOne($select);
    }

    public function fetchImportStatistics()
    {
        $query = "SELECT 'imported_properties' AS stat_name, COUNT(*) AS stat_value"
          . "   FROM import_run i"
          . "   JOIN imported_rowset_row rs ON i.rowset_checksum = rs.rowset_checksum"
          . "   JOIN imported_row_property rp ON rp.row_checksum = rs.row_checksum"
          . "  UNION ALL"
          . " SELECT 'imported_rows' AS stat_name, COUNT(*) AS stat_value"
          . "   FROM import_run i"
          . "   JOIN imported_rowset_row rs ON i.rowset_checksum = rs.rowset_checksum"
          . "  UNION ALL"
          . " SELECT 'unique_rows' AS stat_name, COUNT(*) AS stat_value"
          . "   FROM imported_row"
          . "  UNION ALL"
          . " SELECT 'unique_properties' AS stat_name, COUNT(*) AS stat_value"
          . "   FROM imported_property"
          ;
        return $this->db()->fetchPairs($query);
    }

    public function getImportrunRowsetChecksum($id)
    {
        $db = $this->db();
        $query = $db->select()
            ->from('import_run', 'rowset_checksum')
            ->where('id = ?', $id);

        return $db->fetchOne($query);
    }

    public function fetchTemplateTree($type)
    {
        $db = $this->db();
        $query = $db->select()->from(
            array('p' => 'icinga_' . $type),
            array(
                'name'   => 'o.object_name',
                'parent' => 'p.object_name'
            )
        )->join(
            array('i' => 'icinga_' . $type . '_inheritance'),
            'p.id = i.parent_' . $type . '_id',
            array()
        )->join(
            array('o' => 'icinga_' . $type),
            'o.id = i.' . $type . '_id',
            array()
        )->where("o.object_type = 'template'")
         ->order('p.object_name')
         ->order('o.object_name');

        $relations = $db->fetchAll($query);
        $children = array();
        $objects = array();
        foreach ($relations as $rel) {
            foreach (array('name', 'parent') as $col) {
                if (! array_key_exists($rel->$col, $objects)) {
                    $objects[$rel->$col] = (object) array(
                        'name'     => $rel->$col,
                        'children' => array()
                    );
                }
            }
        }

        foreach ($relations as $rel) {
            $objects[$rel->parent]->children[$rel->name] = $objects[$rel->name];
            $children[$rel->name] = $rel->parent;
        }

        foreach ($children as $name => $object) {
            unset($objects[$name]);
        }

        return $objects;
    }

    public function fetchLatestImportedRows($source, $columns = null)
    {
        $db = $this->db();
        $lastRun = $db->select()->from('import_run', array('rowset_checksum'));

        if (is_int($source) || ctype_digit($source)) {
            $lastRun->where('source_id = ?', $source);
        } else {
            $lastRun->where('source_name = ?', $source);
        }

        $lastRun->order('start_time DESC')->limit(1);
        $checksum = $db->fetchOne($lastRun);

        return $this->fetchImportedRowsetRows($checksum, $columns);
    }

    public function listImportedRowsetColumnNames($checksum)
    {
        $db = $this->db();

        $query = $db->select()->distinct()->from(
            array('p' => 'imported_property'),
            'property_name'
        )->join(
            array('rp' => 'imported_row_property'),
            'rp.property_checksum = p.checksum',
            array()
        )->join(
            array('rsr' => 'imported_rowset_row'),
            'rsr.row_checksum = rp.row_checksum',
            array()
        )->where('rsr.rowset_checksum = ?', $checksum);

        return $db->fetchCol($query);
    }

    public function createImportedRowsetRowsQuery($checksum, $columns = null)
    {
        $db = $this->db();

        $query = $db->select()->from(
            array('r' => 'imported_row'),
            array()
        )->join(
            array('rsr' => 'imported_rowset_row'),
            'rsr.row_checksum = r.checksum',
            array()
        )->where('rsr.rowset_checksum = ?', $checksum);

        $propertyQuery = $db->select()->from(
            array('rp' => 'imported_row_property'),
            array(
                'property_value' => 'p.property_value',
                'row_checksum'   => 'rp.row_checksum'
            )
        )->join(
            array('p' => 'imported_property'),
            'rp.property_checksum = p.checksum',
            array()
        );

        $fetchColumns = array('object_name' => 'r.object_name');
        if ($columns === null) {
            $columns = $this->listImportedRowsetColumnNames($checksum);
        }

        foreach ($columns as $c) {
            $fetchColumns[$c] = sprintf('p_%s.property_value', $c);
            $p = clone($propertyQuery);
            $query->joinLeft(
                array(sprintf('p_' . $c) => $p->where('p.property_name = ?', $c)),
                sprintf('p_%s.row_checksum = r.checksum', $c),
                array()
            );
        }

        $query->columns($fetchColumns);

        return $query;
    }

    public function fetchImportedRowsetRows($checksum, $columns = null)
    {
        return $this->db()->fetchAll(
            $this->createImportedRowsetRowsQuery($checksum, $columns)
        );
    }

    public function enumCommands()
    {
        return $this->enumIcingaObjects('command');
    }

    public function enumCheckcommands()
    {
        $filters = array(
            'object_type IN (?)' => array('object', 'external_object'),
            'methods_execute IN (?)' => array('PluginCheck', 'IcingaCheck'),
            
        );
        return $this->enumIcingaObjects('command', $filters);
    }

    public function getZoneName($id)
    {
        $objects = $this->enumZones();
        return $objects[$id];
    }

    public function getCommandName($id)
    {
        $objects = $this->enumCommands();
        return $objects[$id];
    }

    public function enumZones()
    {
        return $this->enumIcingaObjects('zone');
    }

    public function enumZoneTemplates()
    {
        return $this->enumIcingaTemplates('zone');
    }

    public function enumHosts()
    {
        return $this->enumIcingaObjects('host');
    }

    public function enumHostTemplates()
    {
        return $this->enumIcingaTemplates('host');
    }

    public function enumHostgroups()
    {
        return $this->enumIcingaObjects('hostgroup');
    }

    public function enumServices()
    {
        return $this->enumIcingaObjects('service');
    }

    public function enumServiceTemplates()
    {
        return $this->enumIcingaTemplates('service');
    }

    public function enumServicegroups()
    {
        return $this->enumIcingaObjects('servicegroup');
    }

    public function enumUsers()
    {
        return $this->enumIcingaObjects('user');
    }

    public function enumUserTemplates()
    {
        return $this->enumIcingaTemplates('user');
    }

    public function enumUsergroups()
    {
        return $this->enumIcingaObjects('usergroup');
    }

    public function enumSyncRule()
    {
        return $this->enum('sync_rule', array('id', 'rule_name'));
    }

    public function enumImportSource()
    {
        return $this->enum('import_source', array('id', 'source_name'));
    }

    public function enumDatalist()
    {
        return $this->enum('director_datalist', array('id', 'list_name'));
    }

    public function enumDatafields()
    {
        return $this->enum('director_datafield', array(
            'id',
            "caption || ' (' || varname || ')'",
        ));
    }

    public function enum($table, $columns = null, $filters = array())
    {
        if ($columns === null) {
            $columns = array('id', 'object_name');
        }

        $select = $this->db()->select()->from($table, $columns)->order($columns[1]);
        foreach ($filters as $key => $val) {
            $select->where($key, $val);
        }

        return $this->db()->fetchPairs($select);
    }

    public function enumIcingaObjects($type, $filters = array())
    {
        $filters = array('object_type = ?' => 'object') + $filters;
        return $this->enum('icinga_' . $type, null, $filters);
    }

    public function enumIcingaTemplates($type, $filters = array())
    {
        $filters = array('object_type = ?' => 'template') + $filters;
        return $this->enum('icinga_' . $type, null, $filters);
    }
}
