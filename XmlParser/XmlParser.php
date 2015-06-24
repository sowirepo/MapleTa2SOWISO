<?php
namespace TaConverter;
require 'Preparer.php';
require dirname(__FILE__).'/../ProgressBar.php';

/**
 * Class XmlParser
 * @package TaConverter
 * Builds XML out of array filled with data from quBank
 */
class XmlParser {
    private $preparer;
    private $progressBar;
    private $ci;

    function __construct() {
        $this->preparer = new XmlParser\Preparer();
        $this->progressBar = new \ProgressBar();
    }

    /**
     * @param $data
     * @return string
     *
     * Takes array filled with exercise data and returns formatted XML
     */
    public function parse($data) {
        # Initialize progress bar
        $size=sizeof($data);
        echo '<div style="width: 300px; text-align: center;">';
        echo '<div id="conversionStatus">Conversion in progress. Please wait.</div>';
        $this->progressBar->render();
        echo '</div>';

        # Initialize XML
        $xml = new \SimpleXMLElement('<sowiso export_version="2"></sowiso>');
        $sets = $xml->addChild('sets');

        # Populate XML
        $i=0;
        foreach($data as $exercise) {
            $setsSub = $sets->addChild('set');
            $setsSub->addAttribute('id', $i);
            if($exercise['mode'] != 'List' && $exercise['mode'] != 'Short Phrase' && $exercise['mode'] != 'Clickable Image' && $exercise['mode'] != 'Multipart Formula')
                //^those modes are not needed. The only questions that use them are sample questions
                $preparedData = $this->preparer->prepare($exercise);
            if(!isset($preparedData['mode']) || $preparedData['mode'] != 'Multipart') {
                $xmlItem = $setsSub->addChild('item');
                $this->_toXml($preparedData, $xmlItem);
            } else {
                # Multipart question
                foreach($preparedData['part'] as $preparedPart) {
                    $xmlItem = $setsSub->addChild('item');
                    $this->_toXml($preparedPart, $xmlItem);
                }
            }
            $i++;

            # Update progress bar
            $this->progressBar->setProgressBarProgress($i*100/$size);
        }
        # Finish progress bar
        echo '<script>document.getElementById("conversionStatus").innerHTML = "Conversion finished!";</script>';
        $this->progressBar->setProgressBarProgress(100);

        return $this->_formatXml($xml);
    }

    /**
     * @param array $data
     * @param \SimpleXMLElement $xml
     *
     * Builds an XML Document from a structured associative array
     */
    private function _toXml(Array $data, \SimpleXMLElement $xml) {
        foreach($data as $key => $value) {
            if(is_array($value)) {
                if(!is_numeric($key)){
                    $subNode = $xml->addChild("$key");
                    $this->_toXml($value, $subNode);
                } else {
                    $subNode = ($xml->getName() == 'exercise_vars') ? $xml->addChild('exercise_var') : $xml->addChild('item');
                    $this->_toXml($value, $subNode);
                }
            } else {
                $xml->addChild("$key",htmlspecialchars("$value"));
            }
        }
    }

    /**
     * @param \SimpleXMLElement $xml
     * @return string
     *
     * Formats XML
     */
    private function _formatXml(\SimpleXMLElement $xml) {
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        return $dom->saveXML();
    }
}