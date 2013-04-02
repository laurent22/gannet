<?php

// https://github.com/zidizei/toml-php

class Toml {

    private $in;
    private $out;

    private $currentGroup;
    private $currentLinenumber = 1;

    public static function parse ($input)
    {
        $p = new self($input);

        return $p->out;
    }

    public static function parseFile ($input)
    {
        if (is_file($input) && is_readable($input)) {
            $input = file_get_contents($input);
        } else {
            throw new \InvalidArgumentException("Could not open TOML file '".$input."'.");
        }

        return self::parse($input);
    }

    private function __construct ($input)
    {
        // Splitting at the last \n before '=', '[' or # 
        $this->in = preg_split('/\r\n|\r|\n(?=\s*\w+\s*=|\[|\n|#.*)/s', $input);
        $this->currentGroup = &$this->out;

        foreach ($this->in as &$row)
        {
            $this->parseLine($row);
            $this->currentLinenumber += (1 + substr_count($row, "\n"));
        }
    }

    private function parseLine (&$row)
    {
        // Removing comments
        $line = preg_replace('/#(?=(?:(?:[^"]*+"){2})*+[^"]*+\z).*/', '', $row);
        $line = trim($line);

        if (empty($line)) {
            // An empty line will leave the current key group
            $this->currentGroup = &$this->out;
            return;
        }

        $row = $line;

        // Parse data
        if (preg_match('/^(\S+)\s*=\s*(.*)$/s', $row, $match))
        {
            if (isset($this->currentGroup[$match[1]])) {
                throw new \Exception("Duplicate entry found for '".$row."' on line ".$this->currentLinenumber.".");
                return;
            }

            $this->currentGroup[$match[1]] = $this->parseValue($match[2]);
            return;
        }

        // Create key group
        if (preg_match('/^\[([^\]]+)\]$/s', $line, $matches))
        {
            $m = explode('.', $matches[1]);
            $group = &$this->out[$m[0]];

            for ($i=1; $i<count($m); $i++)
            {
                $group = &$group[$m[$i]];
            }

            $this->currentGroup = &$group;
            return;
        }

        throw new \UnexpectedValueException("Invalid TOML syntax '".$row."' on line ".$this->currentLinenumber.".");
    }

    private function parseValue ($value)
    {
        $value = trim($value);
        
        if ($value === "") throw new \UnexpectedValueException("Value cannot be empty on line ".$this->currentLinenumber);

        // Parse bools
        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        // Parse floats
        if (preg_match('/^\-?\d*?\.\d+$/', $value)) {
            return (float) $value;
        }

        // Parse integers
        if (preg_match('/^\-?\d*?$/', $value)) {
            return (int) $value;
        }

        // Parse datetime
        if (strtotime($value)) {
            return $date = new \Datetime($value);
        }

        // Parse string
        if (preg_match('/^"(.*)"$/u', $value, $match)) {
            return $this->parseString($match[1]);
        }

        // Parse arrays
        if (preg_match('/^\[(.*)\]$/s', $value, $match)) {
            return $this->parseArray($match[1]);
        }

        throw new \UnexpectedValueException("Data type '".$value."' not recognized on line ".$this->currentLinenumber.".");
    }


    private function parseArray ($arr)
    {
        if (preg_match_all('/(?<=\[)[^\]]+(?=\])/s', $arr, $m)) {
            // Match nested Arrays
            $values = $m[0];
        } else {
            // We couldn't find any, so we assume it's a regular flat Array
            $values = preg_split('/,(?=(?:(?:[^"]*+"){2})*+[^"]*+\z)/s', $arr);
        }

        // If the $values Array is not greater than 2, $arr is a single value,
        // so we parse and return it to break the recursion
        if (count($values) <= 1) return $this->parseValue($arr);

        $prevType = '';

        // Iterate through nested Arrays...
        foreach ($values as &$sub)
        {
            // ... and parse them for more nested Arrays
            $sub = $this->parseArray($sub);

            // Don't allow mixing of data types in an Array
            if (empty($prevType) || $sub == null) {
                $prevType = gettype($sub);
            } else if ($prevType != gettype($sub)) {
                throw new \UnexpectedValueException("Mixing data types in an array is stupid.\n".var_export($values, true)." on line ".$this->currentLinenumber.".");
            }
        }

        // Remove empty Array values
        return array_filter($values);
    }

    private function parseString ($string)
    {
        return strtr($string, array(
            '\\0'  => "\0",
            '\\t'  => "\t",
            '\\n'  => "\n",
            '\\r'  => "\r",
            '\\"'  => '"',
            '\\\\' => '\\',
        ));
    }

    private function __clone() {}
}
