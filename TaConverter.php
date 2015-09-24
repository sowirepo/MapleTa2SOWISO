<?php
require 'XmlParser/XmlParser.php';

/**
 * Class TaConverter
 *
 * Usage: new TaConverter->convert(file $file)
 * Converts MapleTA qu banks to Sowiso XML
 */
class TaConverter
{
    private $xmlParser;
    private $ci;

    public function __construct() {
        $this->ci = & get_instance();
        $this->xmlParser = new TaConverter\XmlParser();
        ini_set('max_execution_time', '0');
    }

    /**
     * @param array $file
     * @param array $languages
     * @return string
     *
     * Takes a file array (quBank) and an array of language ids and returns an XML string
     */
    public function convert(Array $file, Array $languages) {
        $data = $this->_collectData($file);

        # Split qu bank if too many exercises
        if(sizeof($data) > 105) {
            return array('error' => 'Exceeded maximum number of exercises to convert at once (100).', 'splits' => $this->splitQu($file));
        }

        # Convert to XML
        $data = $this->xmlParser->parse($data, $languages);
        return $data;
    }

    /**
     * @param $file
     * @return array
     *
     * Builds an array of exercise data from a quBank file
     */
    private function _collectData($file) {
        $nonConvertibles = array();

        # Renumber exercises
        $i = 0;
        foreach($file as &$line) {
            # Increase number at every new topic
            if(strpos($line, '.topic=') !== FALSE) {
                $i++;
            }
            # For exercises that have axes, give coordinates its own field to avoid duplicates
            if(strpos($line, '.axes=') !== FALSE) {
                $line = str_replace('.axes=', '.axes.coord=', $line);
            }
            $line = preg_replace('/^qu\.\d+\./', 'qu.'. $i .'.', $line);
        } unset($line);

        # Collect data and store in array
        $data = array();
        foreach ($file as $line) {
            if ($line = trim($line)) {
                $line = (substr($line, -1) == '@') ? substr($line, 0, -1) : $line;
                if (preg_match('/^qu\.([\d]+\.[\d]+)\.(\w*)[.]?([^=]*)=(.*)/', $line, $matches)) {
                    if ($matches[3] !== '')
                        $data[$matches[1]][$matches[2]][$matches[3]] = trim($matches[4]);
                    else
                        $data[$matches[1]][$matches[2]] = trim($matches[4]);
                    $oldMatches = $matches;
                } else {
                    if (isset($oldMatches[1]) && strpos($line, '.topic=') === FALSE) {
                        if ($oldMatches[3] !== '')
                            $data[$oldMatches[1]][$oldMatches[2]][$oldMatches[3]] .= (substr($line, 0, 1) != ' ') ? ' '.trim($line) : trim($line);
                        else
                            $data[$oldMatches[1]][$oldMatches[2]] .= (substr($line, 0, 1) != ' ') ? ' '.trim($line) : trim($line);
                    }
                }
            }
        }

        # Multipart parts and non-convertibles
        foreach($data as $key=>&$exercise) {
            if(isset($exercise['part']) && is_array($exercise['part'])) {
                $parts = array();
                foreach($exercise['part'] as $field=>$line) {
                    # Non-convertibles
                    if(strpos($field, 'mode') !== FALSE && $line == 'Multipart') {
                        $nonConvertibles[] = $exercise;
                        unset($data[$key]);
                    } else {
                        $number = substr($field, 0, strpos($field, '.'));
                        $field = substr($field, strpos($field, '.') + 1);
                        if(strpos($field, '.') !== FALSE) {
                            $parts[$number][substr($field, 0, strpos($field, '.'))][substr($field, strpos($field, '.') + 1)] = $line;
                        } else {
                            $parts[$number][$field] = $line;
                        }
                    }
                }
                $exercise['part'] = $parts;
            }
        } unset($exercise);
        return $data;
    }

    /**
     * @param $file
     * @return bool|string
     *
     * Takes a quBank file array, splits the exercises into chunks of 100 and creates a zip file from them.
     * Returns the path to the zip file.
     * Returns false if zip can't be created.
     */
    public function splitQu($file) {
        # Get split positions
        $exerciseCount = 1;
        $lineCount = 0;
        $increase = TRUE;
        $splitPositions = array();
        foreach($file as $line) {
            $lineCount++;
            if($increase || empty($oldNumber)) {
                if(preg_match('/(qu\.\d+\.\d+)/', $line, $oldNumber))
                    $increase = FALSE;
            } else {
                if(preg_match('/(qu\.\d+\.\d+)/', $line, $newNumber) && $newNumber[0] != $oldNumber[0]) {
                    $exerciseCount++;
                    if($exerciseCount % 100 == 0) {
                        $splitPositions[] = $lineCount-1;
                    }
                    $increase = TRUE;
                }
            }
        }

        # Split into chunks
        $splits = array();
        $oldPos = 0;
        foreach($splitPositions as $splitPos) {
            $splits[] = array_slice($file, $oldPos, $splitPos - $oldPos);
            $oldPos = $splitPos;
        }
        $splits[] = array_slice($file, $splitPos);

        # Manage folder
        $userfolder = dirname(__FILE__) . '/../../../private/'.$this->ci->tank_auth->get_user_id().'/';
        $type = 'mapleuploads';
        if (!is_dir($userfolder)) {
            mkdir($userfolder, 0755);
        }
        if (!is_dir($userfolder . $type . '/')) {
            mkdir($userfolder . $type . '/', 0755);
        }

        # Create zip file
        $zip = new ZipArchive();
        $i=0;
        while(file_exists($userfolder . $type .  "/splitted$i.zip")) {
            $i++;
        }
        $filename = "splitted$i.zip";
        $filepath = "$userfolder$type/$filename";
        if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
            return FALSE;
        }
        $i=1;
        foreach($splits as $split) {
            $zip->addFromString("Part$i.qu", implode('', $split));
            $i++;
        }
        $zip->close();

        return '/files/' . $this->ci->tank_auth->get_user_id() . '/mapleuploads/' . $filename;
    }
}