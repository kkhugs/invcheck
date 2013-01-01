<?PHP

// my_db extension of class mysqli


class my_db extends mysqli {
	
	public function lookup_singular($query) {
		$result = $this->query($query);
		if ($result) {
			$result = $result->fetch_array();
			if ($result !== null) {
				return $result[0];
			}
		}
		return null;
	}
	
	public function get_assoc($query) {
		$result = $this->query($query);
		if ($result) {
			return $result->fetch_assoc();
		}
		return null;
	}
}

?>