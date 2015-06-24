<?php
namespace TaConverter\XmlParser;
require dirname(__FILE__).'/../PhpParser/lib/bootstrap.php';
require dirname(__FILE__).'/../Traverser.php';

/**
 * Class Preparer
 * @package TaConverter\XmlParser
 * Stores collected data in a properly structured manner according to Sowiso's XML structure standards
 */
class Preparer {
    private $data;
    private $exercise_id = 0;
    private $set_id = 0;
    private $mathMLEntities;
    private $mml2texProcessor;
    private $currentType;
    private $texts = array();
    private $newVars = array();
    private $parser;
    private $prettyPrinter;
    private $traverser;
    private $taFunctions = array(
        'eq', 'ge', 'le', 'ne', 'not', 'gt', 'gt', 'lt', 'if', 'decimal', 'int', 'sig', 'lsu',
        'sum', 'switch', 'rint', 'rand', 'range', 'fact', 'frac', 'gcd', 'indexof', 'rank',
        'numfmt', 'max', 'min', 'strcat', 'binomial', 'erf', 'inverf', 'invstudentst', 'studentst',
        'java', 'maple', 'mathml', 'plotmaple', 'cos', 'sin', 'tan', 'csc', 'sec', 'cot', 'abs', 'sqrt',
        'arcsin', 'arccos', 'arctan', 'hypsin', 'hypcos', 'hyptan', 'archhypsin', 'archypcos', 'archyptan',
        'ln', 'log', 'frac'
    );
    private $exercise_comment;
    private $nonConvertibles;
    private $restrictedFormula = FALSE;
    private $ci;
    private $APIKey = '';
    public function __construct() {
        # Preparing XSLT for MathML to LaTEX conversion
        $this->mathMLEntities = file_get_contents(dirname(__FILE__).'/../xsltsl/entities.txt');
        $xslDoc = new \DOMDocument();
        $xslDoc->load(dirname(__FILE__).'/../xsltsl/mmltex.xsl');
        $this->mml2texProcessor = new \XSLTProcessor();
        $this->mml2texProcessor->importStylesheet($xslDoc);
        $this->ci = & get_instance();
        $this->ci->load->library('session');
        $this->ci->load->helper('url');
        $this->ci->load->model(['db/admin_db']);
        $this->ci->load->model(['admin_model']);

        $this->parser = new \PhpParser\Parser(new \PhpParser\Lexer);
        $this->prettyPrinter = new \PhpParser\PrettyPrinter\Standard;
        $this->traverser = new \PhpParser\NodeTraverser;
        $this->traverser->addVisitor(new \PhpParser\Traverser);
    }

    /**
     * @param $data
     * @return array
     *
     * Takes an array filled with exercise data and returns a properly structured array.
     * Prepares and converts data for XmlParser one exercise at a time
     */
    public function prepare($data) {
        $this->data = $data;

        if($data['mode'] == 'Multipart') {
            return $this->_prepareMultiparts($data);
        } elseif($data['mode'] == 'Inline') {
            $this->_prepareInlines();
        }

        $this->exercise_comment = '';
        $this->exercise_comment .= 'Maple uid:' . PHP_EOL . (isset($data['uid']) ? $data['uid'] : 'unknown') . PHP_EOL;
        $this->nonConvertibles = array();

        # Remove conditions from algorithm string and store them
        if(preg_match_all('/(condition[ ]?:[ ]?.*;)/U', $this->data['algorithm'], $matches)) {
            foreach($matches[1] as $match) {
                $this->data['algorithm'] = str_replace($match, '', $this->data['algorithm']);
            }
            $this->data['conditions'] =  $matches[1];
        } else
            $this->data['conditions'] = array();

        # Prepare answers & data
        $this->_prepareAnswers();
        $preparedData = array(
            'exercise' => $this->_prepareExercise(),
            'exercise_buggy' => $this->_prepareBuggies(),
            'exercise_vars' => $this->_prepareVars(),
            'exercise_solution' => $this->_prepareSolutions(),
            'exercise_text' => $this->_prepareTexts(),
        );

        # Test variables
        if($preparedData['exercise_vars'] != '') {
            $preparedData['exercise_vars'] = $this->_testVars($preparedData['exercise_vars']);
        }

        # Add non-convertibles to comments
        if(!empty($this->nonConvertibles)) {
            sort($this->nonConvertibles);
            $this->exercise_comment .= PHP_EOL . 'The following variables could not be converted:' . PHP_EOL;
            foreach($this->nonConvertibles as $nonConvertible)
                $this->exercise_comment .= '$' . $nonConvertible . ', ';
            $this->exercise_comment = substr($this->exercise_comment, 0, -2) . PHP_EOL;
        }

        # Add maple field to comment
        if(isset($this->data['maple']) && !empty($this->data['maple'])) {
            $this->exercise_comment .= PHP_EOL . 'Maple evaluation:' . PHP_EOL . $this->data['maple'];
        } elseif(isset($this->data['inline_maple_evaluation'])) {
            $this->exercise_comment .= PHP_EOL . 'Maple evaluations:' . PHP_EOL;
            foreach($this->data['inline_maple_evaluation'] as $ev) {
                $this->exercise_comment .= $ev;
            }
        }

        $preparedData['exercise']['exercise_comment'] = $this->exercise_comment;
        $this->exercise_id++;
        $this->set_id++;
        return $preparedData;
    }

    /**
     * Prepares answers and if necessary, creates variables for answers
     */
    private function _prepareAnswers() {
        if (isset($this->data['answer'])) {
            # Check if solution contains function. If yes, store in variable and use variable as solution
            $containsFunction = (isset($this->data['answer']['num'])) ? $this->_containsFunction($this->data['answer']['num']) : $this->_containsFunction($this->data['answer']);
            if (!$containsFunction) {
                if (!is_array($this->data['answer'])) {
                    if (strpos($this->data['answer'], ', ') !== FALSE)
                        $this->data['answer'] = explode(', ', $this->data['answer']);
                }
            } else {
                if($this->data['algorithm'] != '' && substr(trim($this->data['algorithm']), -1) != ';')
                    $this->data['algorithm'] .= ';';

                # Remove units (and paste to comments)
                if(is_array($this->data['answer']) && isset($this->data['answer']['num'])) {
                    if(isset($this->data['answer']['unit']) && !empty($this->data['answer']['unit']))
                        $this->exercise_comment .= PHP_EOL . 'This exercise\'s answer uses a unit: ' . PHP_EOL . $this->data['answer']['unit'];
                    $this->data['answer'] = $this->data['answer']['num'];
                }

                # Deal with multiple answers
                if(strpos(substr($this->data['answer'], 0, -1), ';') !== FALSE) {
                    $this->data['answer'] = explode(';', $this->data['answer']);
                    if(end($this->data['answer']) == '')
                        array_pop($this->data['answer']);
                }

                # Create answer variables and add them to algorithm
                $answerVars = '';
                if(is_array($this->data['answer']) && !isset($this->data['answer']['num'])) {
                    for($i=1; $i<=sizeof($this->data['answer']); $i++) {
                        $answerVars .= '$answer' . $i . '=' . $this->data['answer'][$i - 1] . ';';
                    }
                } else {
                    $answerVars .= '$answer=' . $this->data['answer'] . ';';
                }
                $this->data['algorithm'] .= $answerVars;
                $this->data['answer'] = '$answer1';
                for($i=2; $i<=substr_count($answerVars, '='); $i++) {
                    $this->data['answer'] .= ', $answer' . $i;
                }
            }
        }
    }

    /**
     * @param $str
     * @return bool
     *
     * Takes a string and checks whether it contains a MapleTA function
     */
    private function _containsFunction($str) {
        foreach ($this->taFunctions as $taFunction) {
            if (strpos($str, $taFunction . '(') !== FALSE)
                return TRUE;
        }
        return FALSE;
    }

    /**
     * @return array
     *
     * Prepares exercise data
     */
    private function _prepareExercise() {
        $exercise = array();
        switch($this->data['mode']) { //todo: delete superfluous cases
             case 'Matching':
                 $this->currentType = 3;
                 $terms = array();
                 $terms_def = array();
                 foreach($this->data['term'] as $key=>$term) {
                     if(is_numeric($key))
                         $terms[] = $term;
                     else
                         $terms_def[] = $term;
                 }
                 $this->data['term'] = $terms;
                 $this->data['term_def'] = $terms_def;
                 $exercise['number_of_input_fields'] = sizeof($this->data['term']);
                 for($i=1; $i<=sizeof($this->data['term']); $i++)
                     $this->data['answer'][] = $i;

                 # Shuffle
                 $order = range(1, count($this->data['answer']));
                 shuffle($order) && array_multisort($order, $this->data['answer'], $this->data['term']);
                 break;
             case 'Blanks':
                 $exercise['number_of_input_fields'] = sizeof($this->data['blank']);
                 $this->currentType = 12;
                 if(isset($this->data['format']) && $this->data['format']['input'] == 'text') {
                     $this->data['dropdown'] = FALSE;
                     if(isset($this->data['blank'])) {
                         # Fix maple's variable representation in quBanks
                         $this->data['blank'] = preg_replace('/%24%7b(\w+)%7d/', '\$$1', $this->data['blank']);
                         $this->data['blank'] = str_replace('%2f', '/', $this->data['blank']);
                     }
                     foreach($this->data['blank'] as $blank)
                         $this->data['answer'][] = $blank;
                 } else {
                     $this->data['dropdown'] = TRUE;
                     $oldBlanks = $this->data['blank'];
                     if(isset($this->data['extra']) && !empty($this->data['extra']))
                         $this->data['blank'] = array_merge($this->data['blank'], explode(',',$this->data['extra']));
                     shuffle($this->data['blank']);
                     foreach($oldBlanks as $blank) {
                         $this->data['answer'][] = array_search($blank, $this->data['blank']) + 1;
                     }
                 }
                 break;
             case 'Multiple Choice':
                 $this->currentType = 7;
                 break;
             case 'Essay':
                 $this->currentType = 14;
                 break;
             case 'Multiple Selection':
             case 'Non Permuting Multiple Selection':
                $this->currentType = 15;
                break;
             case 'Formula':
             case 'Numeric':
             case 'Maple':
                $this->currentType = 1;
                break;
             case 'True False':
                $this->currentType = 2;
                break;
             case 'Inline':
                $this->currentType = 12;
                $exercise['number_of_input_fields'] = sizeof($this->data['part']);
                break;
             case 'Formula Mod C':
                $this->exercise_comment .= PHP_EOL . 'This question deals with indefinite integrals. Please inform the student that the answer needs to be entered without the constant C.' . PHP_EOL;
                break;
             default:
                 $this->currentType = 1;
         }

        # Exercise comment
        $this->exercise_comment .= (isset($this->data['info'])) ? PHP_EOL . 'Info fields:' . PHP_EOL . str_replace(';', PHP_EOL, $this->data['info']) : '';
        if(!empty($this->data['conditions'])) {
            $this->exercise_comment .= PHP_EOL . 'Conditions:' . PHP_EOL;
            foreach($this->data['conditions'] as $condition) {
                $this->exercise_comment .= $condition . PHP_EOL;
            }
        }

        # Shortening long names
        $name = strip_tags($this->data['name']);
        if(strlen($name) > 40)
            $name =  substr($name, 0, (strpos($name, ' ', 40) !== FALSE) ? strpos($name, ' ', 40) : strrpos(substr($name, 0, 40), ' ')) . '...';

        $exercise = array(
            'id' => $this->exercise_id,
            'name' => $name . ' (ta)',
            'exercise_type_id' => $this->currentType,
            'number_of_input_fields' => (isset($exercise['number_of_input_fields'])) ? $exercise['number_of_input_fields'] : 1,
            'status' => 0,
            'next_step_correct' => 0,
            'step_type' => 1,
            'theory_id' => '',
            'palette_id' => 0,
            'set_id' => $this->set_id,
            'set_order' => 0,
            'rating' => 1000,
            'author_group_id' => 1,
            'exercise_comment' => $this->exercise_comment
        );
        return $exercise;
    }


    /**
     * @return string
     *
     * Prepares exercise_buggy data (feedback)
     */
    private function _prepareBuggies() {
        if($this->data['mode'] == 'Restricted Formula' || $this->restrictedFormula) {
            $this->restrictedFormula = TRUE;
            $exercise_buggy[0] = array(
                'ai_id' => 0,
                'id' => 1,
                'exercise_id' => $this->exercise_id,
                'number' => isset($this->data['inline_feedback_number']) ? $this->data['inline_feedback_number'] : 0,
                'rule' => '[arccos,arcsec,arcsin,arctan,cos,cot,sec,sin,tan,ln,log]',
                'low' => 0,
                'high' => 0,
                'precision' => 0,
                'type' => 113,
                'evaluation_category' => 0,
                'priority' => 0,
                'buggy_category_id' => 1,
                'post_type' => 0,
                'not' => 0
            );
            return $exercise_buggy;
        } else
            return '';
    }

    /**
     * @return array|string
     *
     * Prepares exercise_vars data
     */
    private function _prepareVars() {
        if(isset($this->data['algorithm']) && $this->data['algorithm'] != '') {
            $exercise_vars = array();
            $algTemp = $this->data['algorithm'];
            if(strpos($algTemp, ';') !== FALSE) {
                $args = array();
                $offset = 0;

                # Deal with double ';'
                $algTemp = str_replace(';[ ]?+;', ';', $algTemp);
                $algTemp = preg_replace('/\;[ ]*\;/', ';', $algTemp);

                # Filter out strings "(" and ")"
                $algTemp = str_replace(array('"("', '")"'), array('@OBR@', '@CBR@'), $algTemp);

                # Explode variables
                while(strpos($algTemp, ';') !== FALSE) {
                    $pos = strpos($algTemp, ';', $offset);
                    $openBr = substr_count(substr($algTemp, 0, $pos), '(');
                    $closedBr = substr_count(substr($algTemp, 0, $pos), ')');
                    $quotes = substr_count(substr($algTemp, 0, $pos), '"');
                    if($openBr == $closedBr && $quotes % 2 == 0) {
                        $args[] = substr($algTemp, 0, $pos);
                        $algTemp = substr($algTemp, $pos + 1);
                        $offset = 0;
                    } else {
                        $offset = $pos+1;
                    }
                }

                # Place strings back at the right position
                foreach($args as &$arg) {
                    $arg = str_replace(array('@OBR@', '@CBR@'), array('"("', '")"'), $arg);
                } unset($arg);

                # Insert strings back in again
                $this->data['algorithm'] = $args;
            } else {
                $this->data['algorithm'] = array($this->data['algorithm']);
            }

            if(end($this->data['algorithm']) == '')
                array_pop($this->data['algorithm']);

            # Store original ta-vars (Sowiso vars about to be converted)
            $this->data['algorithm'] = array(
                'sowiso' => $this->data['algorithm'],
                'ta' => $this->data['algorithm']
            );

            # Replace arguments of maple() functions with incrementing number, to prevent php errors
            $mapleFunctions = array(); $i = 0;
            foreach($this->data['algorithm']['sowiso'] as &$var) {
                if(preg_match_all('/(maple\(\".*\"\))/U', $var, $matches)) {
                    foreach($matches[1] as $mapleFunc) {
                        $mapleFunctions[] = $mapleFunc;
                        $replace = "maple($i)";
                        //echo "Function: $mapleFunc<br/>Replace:$replace<br/><br/>";
                        //$var = preg_replace($mapleFunc, $replace, 1);
                        $var = str_replace($mapleFunc, $replace, $var);
                        $i++;
                    }
                }
            }

            # Deal with silent multiplication
            $this->data['algorithm']['sowiso'] = $this->_addMultipliers($this->data['algorithm']['sowiso']);

            # Conversion from TA to Sowiso
            $this->data['algorithm']['sowiso'] = $this->_ta2sw($this->data['algorithm']['sowiso']);

            # Replace maple(1), maple(2) ... back to original maple function
            $i = 0; $j = 0; $offset = 0;
            while($j<sizeof($mapleFunctions) && $i<sizeof($this->data['algorithm']['sowiso'])) {
                if($this->data['algorithm']['sowiso'][$i] != '' && strpos($this->data['algorithm']['sowiso'][$i], 'maple', $offset) !== FALSE) {
                    $this->data['algorithm']['sowiso'][$i] = str_replace("maple($j)", $mapleFunctions[$j], $this->data['algorithm']['sowiso'][$i]);
                    $offset = strpos($this->data['algorithm']['sowiso'][$i], $mapleFunctions[$j]) + strlen($mapleFunctions[$j]);
                    if($offset > strlen($this->data['algorithm']['sowiso'][$i])) {
                        $offset = strlen($this->data['algorithm']['sowiso'][$i]) - 1;
                    }

                    $j++;
                } else {
                    $i++;
                    $offset = 0;
                }
            }

            $vars = array();
            for($i=0; $i<sizeof($this->data['algorithm']['ta']); $i++) {
                $varName = trim(substr($this->data['algorithm']['ta'][$i], 0, strpos($this->data['algorithm']['ta'][$i], '=')));
                $vars[$varName] = array(
                    'sowiso' => trim(substr($this->data['algorithm']['sowiso'][$i], strpos($this->data['algorithm']['sowiso'][$i], '=') + 1)),
                    'ta' => trim(substr($this->data['algorithm']['ta'][$i], strpos($this->data['algorithm']['ta'][$i], '=') + 1)),
                );
            }

            # Change variable names to a,b,c,d...
            $lettersAppend = range('a', 'z');
            $wletters = $xletters = $yletters = $zletters = array();
            foreach($lettersAppend as $letter) {
                $wletters[] = 'w' . $letter;
                $xletters[] = 'x' . $letter;
                $yletters[] = 'y' . $letter;
                $zletters[] = 'z' . $letter;
            }
            $letters = array_merge(range('a','h'),range('j','v'),$wletters,$xletters,$yletters, $zletters);

            $replace = array();
            $i = 0;

            if(sizeof($vars) > 0)
                $this->exercise_comment .= PHP_EOL . 'Variable replacement scheme:' . PHP_EOL;
            foreach($vars as $name=>$def) {
                $this->exercise_comment .= $name . ' => $' . $letters[$i] . PHP_EOL;
                $replace[0][] = '/\\' . $name . '([^a-zA-Z0-9])/'; // for replacing var in text
                $replace[1][] = '$'. $letters[$i++] . '$1'; // for replacement. insert captured group at the end
                $replace[2][] = '/\\' . $name . '\\Z/'; // for replacing vars in answer
            }

            list($replace[0], $replace[1], $replace[2]) = array(array_reverse($replace[0]),array_reverse($replace[1]),array_reverse($replace[2]));
            $this->newVars = $replace;
            $i = 0;

            foreach($vars as $name=>&$def) {
                # Definition and comment
                if($def['sowiso'] == '') {
                    $definition = 1;
                    $comment = "Variable conversion failed. Original variable: $name=".$def['ta'];
                    $this->nonConvertibles[] = $letters[$i];
                } elseif(strpos($def['sowiso'], 'maple(') !== FALSE) {
                    // todo conversion
                    $def['sowiso'] = $this->_mapleVarConvert($def['sowiso']);
                    if(strpos($def['sowiso'], 'maple(') !== FALSE) {
                        $definition = 1;
                        $comment = 'This maple function could not be converted:' . PHP_EOL . $name . '=' . $def['ta'];
                        $this->nonConvertibles[] = $letters[$i];
                    } else {
                        $definition = $def['sowiso'];
                        $comment = 'Original variable:' . PHP_EOL . $name . '=' . $def['ta'];
                    }
                }/*elseif(strpos($def['sowiso'], 'mathml(') !== FALSE) {
                    $definition = 1;
                    $comment = 'Variable could not be converted:' . PHP_EOL . $name . '=' . $def['ta'];
                }*/ else {
                    $definition = $def['sowiso'];
                    $comment = 'Original variable:' . PHP_EOL . $name . '=' . $def['ta'];
                }
                $definition = preg_replace($this->newVars[0], $this->newVars[1], $definition);

                # Remove semi-colon
                if(substr($definition, -1) == ';')
                    $definition = substr($definition, 0, -1);

                $exercise_vars[$i] = array(
                    'id' => $i,
                    'exercise_id' => $this->exercise_id,
                    'name' => $letters[$i],
                    'definition' => $definition,
                    'number_of_decimals' => 0,
                    'properties' => '0000000000',
                    'load_sequence' => $i,
                    'comment' => $comment
                );
                $i++;

            }
            return $exercise_vars;
        } else
            return '';
    }

    /**
     * @return array
     *
     * Prepares exercise_solution data
     */
    private function _prepareSolutions() {
        $exercise_solution = array();
        $answers = '';

        if(isset($this->data['answer']) || isset($this->data['maple_answer']) || isset($this->data['inline_answer'])) {
            if(isset($this->data['answer'])) {
                $this->data['answer'] = preg_replace('/\$\{(\w+)\}/', '\$$1', $this->data['answer']);
                $answers = (is_array($this->data['answer'])) ? $this->data['answer'] : array($this->data['answer']);
                if(isset($answers['units']) && isset($answers['num'])) {
                    $answers[] = $answers['num'];
                    unset($answers['units']);
                    unset($answers['num']);
                }
            } elseif(isset($this->data['maple_answer'])) {
                $answers = array($this->data['maple_answer']);
            } elseif(isset($this->data['inline_answer'])) {
                $answers = $this->data['inline_answer'];
                foreach($answers as &$ans) {
                    if(is_array($ans['answer']) && isset($ans['answer']['num']))
                        $ans = $ans['answer']['num'];
                } unset($ans);
            }

            # Replace Vars
            foreach($answers as &$ans) {
                if(isset($this->newVars) && sizeof($this->newVars) > 0) {
                    if(isset($ans['answer'])) {
                        $ans['answer'] = preg_replace($this->newVars[2], $this->newVars[1], $ans['answer']);
                        $ans['answer'] = preg_replace($this->newVars[0], $this->newVars[1], $ans['answer']);
                    } else {
                        $ans = preg_replace($this->newVars[2], $this->newVars[1], $ans);
                        $ans = preg_replace($this->newVars[0], $this->newVars[1], $ans);
                    }
                }
            } unset($ans);

            # Specifics
            if($this->data['mode'] == 'Multi Formula') {
                $type = 18;
                foreach($answers as &$ans)
                    $ans = '[' . str_replace(';', ',', $ans) . ']';
                unset($ans);
            } elseif($this->data['mode'] == 'Formula List') {
                $type = 18;
                $answers = array('[' . implode(',', $answers) . ']');
                unset($ans);
            } elseif($this->data['mode'] == 'Ntuple') {
                $type = 18;
                if($answers[0][0] != '(' && $answers[0][strlen($answers[0])-1] != ')') // Ntuple always has just one answer
                    $answers[0] = '[' . $answers[0] . ']';
                else
                    $answers[0] = '[' . substr($answers[0], 1, -1) . ']';
            } elseif($this->data['mode'] == 'Equation') {
                $type = 2;
            } elseif($this->data['mode'] == 'Matrix') {
                $type = 4;
                $dimensions = explode(',', $this->data['size']);
                $matrix = 'matrix(';
                $k=0;
                for($i=0; $i<$dimensions[1]; $i++) {
                    $matrix .='[';
                    for($j=0; $j<$dimensions[0]; $j++) {
                        $matrix .= ($j != $dimensions[0]-1) ? trim($answers[$k++]) . ', ' : trim($answers[$k++]);
                    }
                    $matrix .= ($i != $dimensions[1] - 1) ? '], ' : ']';
                }
                $matrix .= ')';
                $answers = array($matrix);
            } else {
                    $type = 1;

            }

            for($i=0; $i<(sizeof($answers)); $i++) {
                $exercise_solution[$i] = array(
                    'id' => $i,
                    'exercise_id' => $this->exercise_id,
                    'number' => isset($this->data['blank']) ? $i : (isset($answers[$i]['number']) ? $answers[$i]['number'] : 0),
                    'solution' => isset($answers[$i]['answer']) ? $answers[$i]['answer'] : $answers[$i],
                    'low' => 0,
                    'high' => 0,
                    'precision' => 8,
                    'type' => ($this->data['mode'] == 'Inline') ? $this->data['inline_answer_type'][$i] : $type,
                    'evaluation_category' => 1
                );
            }
        }
        return $exercise_solution;
    }

    /**
     * @return array
     *
     * Prepares exercise_text data
     */
    private function _prepareTexts() {
        $exercise_text = array();
        $this->texts = array('question' => $this->data['question'], 'title' => $this->data['name']);

        # Question type Matching
        if(isset($this->data['term'])) {
            foreach($this->data['term'] as $key=>$term) {
                    $this->texts['post_input_' . $key] = $term;
                    $this->texts['pre_input_' . $key] = '';
                    $this->texts['option_'. ++$key] = $key;
            }
            foreach($this->data['term_def'] as $key=>$def) {
                $this->texts['question'] .= '<br/>' . ++$key . '. ' . $def;
            }
        }

        # Hints
        if(isset($this->data['hint']) && is_array($this->data['hint']))
            for($i=1; $i<=sizeof($this->data['hint']); $i++)
                $this->texts['hint_' . $i] = $this->data['hint'][$i];

        # Multiples
        if(isset($this->data['choice']) && is_array($this->data['choice']))
            for($i=1; $i<=sizeof($this->data['choice']); $i++)
                $this->texts['option_' . $i] = $this->data['choice'][$i];

        //todo
        if(isset($this->data['comment']) && is_array($this->data['comment']))
            $this->data['comment'] = ''; // happens a few times. todo: needs feedback rule

        # Solution
        if(isset($this->data['comment']) && $this->data['comment'] != '')
            $this->texts['solution'] = $this->data['comment'];

        # Blank and Inline specifics
        if ($this->data['mode'] == 'Blanks') {
            $this->texts['input_area'] = $this->data['question'];
            if ($this->data['dropdown']) {
                $replace = '#dropdown(' . implode(',', $this->data['blank']) . ')#';
            } else {
                $replace = '#input#';
            }
            $this->texts['input_area'] = preg_replace('/<\d{1,2}>/', " $replace ", $this->texts['question']);
            $this->texts['question'] = 'Fill in the Blanks';
        } elseif($this->data['mode'] == 'Inline' && isset($this->data['input_area'])) {
            $this->texts['input_area'] = $this->data['input_area'];
        }

        # System requires empty post/pre fields, otherwise error
        if(!isset($this->texts['post_input_0']))
            $this->texts['post_input_0'] = '';
        if(!isset($this->texts['pre_input_0']))
            $this->texts['pre_input_0'] = '';

        # Restricted Formula feedback rule text
        if($this->restrictedFormula)
            $this->texts['1'] = 'You are not allowed to enter logarithms or trigonometric functions.';

        # Replace ${x} with $x
        $this->texts = preg_replace('/\$\{(\w+)\}/', '\$$1', $this->texts);

        # Replace old variable names with new ones
        if(!empty($this->newVars))
            $this->texts = preg_replace($this->newVars[0], $this->newVars[1], $this->texts);

        $i=0;
        foreach($this->texts as $key=>$text) {
            $exercise_text[$i] = array(
                'id' => $i,
                'key' => $key,
                'language_id' => 1,
                'exercise_id' => $this->exercise_id,
                'text' => $this->_mml2tex($text),
                'author_text' => ''
            );
            $i++;
        }

        return $exercise_text;
    }

    /**
     * @param $data
     * @return mixed
     *
     * Makes sure every part is treated as an individual exercise. Returns completely prepared data.
     */
    private function _prepareMultiparts($data) {
        $partsNo = sizeof($data['part']);
        $parts = array();
        $alg = $data['algorithm'];
        $name = $data['name'];
        $i = 1;

        # If it exists, add 'main question' to the first sub question
        if(isset($data['question']) && $data['question'] != '')
            $data['part'][1]['question'] = $data['question'] . $data['part'][1]['question'];

        # Treat every part as individual exercise
        foreach($data['part'] as $part) {

            # Add missing fields
            $part['name'] = $name . ' (' . $i . '/' . $partsNo . ')';
            $part['algorithm'] = (isset($exercise_vars)) ? '' : $alg;
            $part['solution'] = '';

            $part = $this->prepare($part);

            # Make sure all variables are only converted once (to save memory and time)
            if(!isset($exercise_vars))
                $exercise_vars = $part['exercise_vars'];
            else {
                $part['exercise_vars'] = $exercise_vars;
                if(isset($part['exercise_vars']) && !empty($part['exercise_vars'])) {
                    # Change to correct exercise_id
                    foreach($part['exercise_vars'] as &$variable) {
                        $variable['exercise_id'] = $part['exercise']['id'];
                    } unset($variable);
                }
            }

            # Add set information
            $part['exercise']['step_type'] = $partsNo;
            $part['exercise']['set_order'] = $i - 1;

            $parts[] = $part;
            $i++;
        }
        $data['part'] = $parts;
        unset($parts); unset($exercise_vars);
        return $data;
    }

    /**
     * Prepares inline questions.
     * Builds 'input_area' with correct input field.
     * Prepares 'inline_answers' array entry with answers
     */
    private function _prepareInlines() {
        $this->data['input_area'] = $this->data['question'];
        $this->data['question'] = '';
        $this->data['inline_answer'] = array();
        $this->data['inline_answer_type'] = array();

        $i=1;
        foreach($this->data['part'] as &$part) {
            switch($part['mode']) {
                case 'True False':
                    $replace = '#dropdown(True,False)#';
                    break;
                case 'Multiple Choice':
                case 'Non Permuting Multiple Choice':
                    $replace = '#dropdown(' . implode(',', $part['choice']) . ')#';
                    break;
                case 'Maple':
                    if(isset($this->data['maple']))
                        $this->data['inline_maple_evaluation'][] = 'Field ' . $i . ': ' . $this->data['maple'] . PHP_EOL;
                    $replace = '#input#';
                    break;
                case 'Non Permuting Multiple Selection':
                case 'Multiple Selection':
                    $replace = '#input# [Multiple Selection not possible here - Options: (' . implode(',', $part['choice']) . '), Answers: (' . $part['answer'] . ')]';
                    break;
                case 'Restricted Formula':
                    $replace = '#input#';
                    $this->data['inline_feedback_number'] = $i-1;
                    $this->restrictedFormula = TRUE;
                default:
                    $replace = '#input#';
            }

            if(isset($part['answer'])) {
                if (!is_array($part['answer']) || (is_array($part['answer']) && isset($part['answer']['num']))) {
                    $this->data['inline_answer'][] = array('number' => $i - 1, 'answer' => (isset($part['answer']['num']) ? $part['answer']['num'] : $part['answer']));
                } else {
                    foreach ($part['answer'] as $ans) {
                        $this->data['inline_answer'][] = array('number' => $i - 1, 'answer' => $ans);
                        $this->data['inline_answer_type'][] = 1;
                    }

                }
            } elseif(isset($part['maple_answer']))
                $this->data['inline_answer'][] = array('number' => $i - 1, 'answer' => $part['maple_answer']);
            if($part['mode'] == 'Ntuple') {
                if($this->data['inline_answer'][$i-1]['answer'][0] != '(' && $this->data['inline_answer'][$i-1]['answer'] != ')')
                    $this->data['inline_answer'][$i-1]['answer'] = '[' . $this->data['inline_answer'][$i-1]['answer'] . ']';
                else
                    $this->data['inline_answer'][$i-1]['answer'] = '[' . substr($this->data['inline_answer'][$i-1]['answer'], 1, -1) . ']';
                $this->data['inline_answer_type'][] = 18;
            } elseif(isset($part['answer']) && (!is_array($part['answer']) || (is_array($part['answer']) && isset($part['answer']['num']))) || isset($part['maple_answer']))
                $this->data['inline_answer_type'][] = 1;

            $this->data['input_area'] = preg_replace('/<' . $i . '>/', $replace, $this->data['input_area']);
            $i++;

        } unset($part);
    }

    /**
     * @param $text
     * @return mixed
     *
     * Takes a string containing MathML and returns LaTEX
     */
    private function _mml2tex($text) {
        # Fix backslash errors in qu files
        $text = str_replace('\\\\\'','\'', $text);

        # Adding possibly missing MathML declaration
        $text = str_replace('<math>', '<math xmlns="http://www.w3.org/1998/Math/MathML">', $text);

        # MathML to LaTEX
        if(preg_match_all('/(<math.*<\/math>)/U', $text, $matches)) {
            foreach($matches[0] as $match) {
                $mathMLDoc = new \DOMDocument();
                $mathMLDoc->substituteEntities = true;
                $mathMLDoc->loadXML($this->mathMLEntities . $match);
                $latex = $this->mml2texProcessor->transformToXML($mathMLDoc);
                $text = str_replace($match, $latex, $text);
            }
        }
        return $text;
    }

    /**
     * @param $variables
     * @return mixed
     *
     * Takes a properly structured array of variables.
     * Uses SOWISO's API to test converted variables for validity
     * Sets variable definition to 1 if found invalid.
     */
    public function _testVars($variables) {
        if(empty($this->APIKey))
            $this->APIKey = $this->_getAPIKey();
        $url = base_url() . 'api/author/test_variables';
        $headers = array(
            "Accept: application/json",
            'X-API-KEY: ' . $this->APIKey
        );
        $context_variables = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $i=0;

        # Check last variable first, to avoid checking the rest if first check is successful
        $fields = [
            'variables' => $variables
        ];
        $field_string = http_build_query($fields);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);
        $result = curl_exec($ch);
        $result = json_decode($result);

        # first check was unsuccessful:
        if(empty($result) || (is_array($result) && !$result[0]) || (is_object($result) && $result->result == 0)) {
            foreach ($variables as &$var) {
                $context_variables[] = $var;
                $fields = [
                    'variables' => $context_variables
                ];
                $field_string = http_build_query($fields);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);
                $result = curl_exec($ch);
                $result = json_decode($result);

                # Variable is invalid:
                if (empty($result) || (is_array($result) && !$result[0]) || (is_object($result) && $result->result == 0)) {
                    $var['comment'] .= PHP_EOL . 'Converted definition ' . $var['definition'] . ' not valid.';
                    $var['definition'] = 1;
                    $this->nonConvertibles[] = $var['name'];
                    $context_variables[$i]['comment'] = $var['comment'];
                    $context_variables[$i]['definition'] = 1;
                }
                $i++;
            }
            curl_close($ch);
        }
        return $variables;
    }

    private function _getAPIKey() {
        $user_owns_key = false;
        $api_keys = $this->ci->admin_db->get_api_keys();
        $username = $this->ci->session->userdata('username');
        foreach($api_keys as $api) {
            if ($api['username'] ==  $username && $api['level'] == 6) {
                $data['key'] = $api['key'];
                $user_owns_key = true;
                break;
            }
        }
        if(!$user_owns_key){
            $data = $this->ci->admin_model->create_api_key($this->ci->session->userdata('user_id'), 6);
        }

        return $data['key'];
    }

    /**
     * @param $vars
     * @return array|mixed
     *
     * Takes an unstructured array of variables.
     * Uses PhpParser to convert definitions from MapleTA to SOWISO
     */
    private function _ta2sw($vars) {
        if(!is_array($vars))
            $vars = array($vars);
        foreach($vars as &$var) {
            $origVar = $var;

            # Replace 'switch' and 'if' functions, because they are php statements and PhpParser does not accept them
            $var = str_replace(array('switch(', 'if('), array('aswitch(', 'aif('), $var);

            # Replace round brackets with square brackets in lists, e.g. (1,2) -> [1,2]
            if(preg_match_all('/[^a-zA-Z](\([-]?\d+,[-]?\d+\))/U', $var, $matches)) {
                foreach($matches[1] as $match) {
                    $var = str_replace($match, '[' . substr($match, 1, -1) . ']', $var);
                }
            }

            # Add <?php opening, and a closing semi-colon, because PhpParser expects php code
            $var = '<?php ' . $var;

            # Add missing semi-colon
            if (substr($var, -1) != ';')
                $var .= ';';

            try {
                $tree = $this->parser->parse($var);
                $tree = $this->traverser->traverse($tree);
                $var = $this->prettyPrinter->prettyPrint($tree);
            } catch(\PhpParser\Error $e) {
                //echo '<p>Parse Error: ', $e->getMessage() , ' in string: ' . $origVar . '</p>';
                $var = '';
            }
        }
        $vars = str_replace(array('{', '}'), '', $vars);
        return $vars;
    }

    /**
     * @param $vars
     * @return mixed
     *
     * Takes an unstructured array of variables.
     * Deals with silent multiplication: Adds all missing asterisks
     */
    private function _addMultipliers($vars) {
        $functionsTemp = array();
        foreach($vars as &$str) {
            $str = str_replace(array(' =', '= '), '=', $str);

            # Replacing all functions with '@', to make sure that they are not subject to the same rules as the rest of the characters
            if (preg_match_all('/([a-z]+)\(/', $str, $match) && sizeof($match[0]) > 0) {
                # Sort array to make sure longer function names get replaced first! ( e.g. problem when replacing le() and maple() )
                $matchTemp = $match;
                usort($matchTemp[0], function($a, $b) {
                    return strlen($b) - strlen($a);
                });

                for ($i = 0; $i < sizeof($matchTemp[0]); $i++) {
                    if (in_array(substr($matchTemp[0][$i], 0, -1), $this->taFunctions)) {
                        $str = str_replace($matchTemp[0][$i], '@', $str);
                        $functionsTemp[] = $match[0][$i];
                    }
                }
            }

        } unset($str);

        # Insertion process begins
        $returnVars = $vars;
        $j = 0;
        foreach($returnVars as &$def) {
            $eqPos = strpos($def, '=');
            $varName = trim(substr($def, 0, $eqPos));
            $varNames[] = $varName;
            $def = trim(substr($def, $eqPos+1)) . ';';
            $offset=0;
            for ($i = 1; $i < strlen($def); $i++) {
                # Case one: e.g. a$b or a1 a(2)
                $case1 = ctype_alpha($def[$i - 1]) && ($def[$i] == '$' || ctype_alpha($def[$i]) || ctype_digit($def[$i]));
                if($case1) {
                    # Extract name of entire variable, to make sure it does not exist (if it exists, no multipliers are inserted between letters and numbers)
                    $endMarker = array('-', '+', '/', '*', '^', ' ', ';', '(', ')', '$', ',');
                    for($start=$i-1; in_array($def[$start],$endMarker)===FALSE && $start>0; $start--);
                    for($end=$i-1; in_array($def[$end],$endMarker)===FALSE; $end++);
                    if(strpos($def, '$', $offset) !== FALSE) {
                        $isVar = (in_array(substr($def, $start, $end-$start), $varNames)) ? TRUE : FALSE;
                        # In case it is no variable, insert * in-between every letter and number
                        if(!$isVar) {
                            $newDef = substr($def, 0, ++$start+1);
                            while($start<$end-1){
                                $newDef .= (is_numeric($def[$start]) && is_numeric($def[$start+1])) ? $def[++$start] : '*' . $def[++$start]; //makes sure that several digits as treated as one number
                            }
                            $def = $newDef . substr($def, $start+1);
                        }
                        # Continue loop at end of current variable
                        $offset=$i+1;
                        $i=$end;
                    } else {
                        # Case one, but no '$' found, means we are dealing with letters that do not belong to a variable
                        $start = ($def[$start] == ' ') ? $start + 1 : $start;
                        $newDef = substr($def, 0, $start);
                        while($start < $end) {
                            $newDef .= (is_numeric($def[$start]) && is_numeric($def[$start+1])) ? $def[$start++] : $def[$start++] . '*'; //makes sure that several digits as treated as one number
                        }
                        $def = substr($newDef, 0, -1) . substr($def, $end); //strip last * and append rest of equation
                        $i=$end;
                    }
                }

                # Case two: e.g. erf(1)$ or erf(1)2 or erf(1)a or erf(1)(2) or (2)@
                $case2 = ($def[$i - 1] == ')' || ctype_alpha($def[$i - 1])) && ($def[$i] == '$' || $def[$i] == '@' || ctype_alnum($def[$i]) || $def[$i] == '(');

                # Case three: e.g. 1(2) or 1$a or 1a
                $case3 = ctype_digit($def[$i - 1]) && ($def[$i] == '(' || $def[$i] == '$' || ctype_alpha($def[$i])|| $def[$i] == '@');
                if ($case2 || $case3) {
                    $def = substr($def, 0, $i) . '*' . substr($def, $i);
                }

                # Case four: e.g. (1) (2) or 3 4 or a 3 or (1) @
                $case4 = (isset($def[$i+1])) ? (ctype_alnum($def[$i-1]) || $def[$i-1] == ')') && $def[$i] == ' ' && ($def[$i+1] == '@' || ctype_alnum($def[$i+1]) || $def[$i+1] == '(') : FALSE;
                if($case4) {
                    $def[$i] = '*';
                }
            }

            # Create final string with inserted multipliers (back to original format)
            $returnVars[$j++] = $varName . ' = ' . $def;
        }

        # Replace every '@' with corresponding function name.
        $i = 0; $j = 0;
        while($j<sizeof($functionsTemp) && $i<sizeof($returnVars)) {
            if(strpos($returnVars[$i], '@') !== FALSE) {
                $returnVars[$i] = preg_replace('/@/', $functionsTemp[$j], $returnVars[$i], 1);
                $j++;
            } else {
                $i++;
            }
        }

        # Ugly hacks
        $returnVars = str_replace('**','*', $returnVars);
        $returnVars = preg_replace('/\[*]?,\*/', ',', $returnVars);
        foreach($returnVars as &$str) {
            if(preg_match_all('/[*]?".*"/U', $str, $matches)) {
                foreach($matches[0] as $match)
                    $str = str_replace($match, str_replace('*','', $match), $str);
            }
        }

        return $returnVars;
    }

    /**
     * @param $definition
     * @return string
     *
     * Takes a string containing a maple(" ... ") function and tries converting it.
     * Returns original string if it fails and converted string if it succeeds.
     */
    private function _mapleVarConvert($definition) {
        $definition = str_replace('`', '', $definition);
        $definition = preg_replace('/Pi(\W)/', 'float(%pi)$1', $definition);
        if(preg_match_all('/(maple\(\"(.*)\"\))/U', $definition, $mapleFunctions)) {
            for($i=0; $i<sizeof($mapleFunctions[1]); $i++) {
                # ExportPresentation (currently converts ~85% of all ExportPresentations)
                if(strpos($mapleFunctions[1][$i], 'ExportPresentation') !== FALSE) {
                    if(preg_match_all('/ExportPresentation[\]]?\((.*)\)\)"\)/U', $mapleFunctions[1][$i], $ep)) {
                        foreach($ep[1] as $e) {
                            if(substr_count($e, '(') != substr_count($e, ')'))
                                $e .= ')';
                            if(!preg_match('/\w+\(/', $e) && strpos($e, '\'') === FALSE && strpos($e, ']') === FALSE && strpos($e, '..') === FALSE)
                                $definition = str_replace($mapleFunctions[1][$i], "sw_maxima(\"$e\")", $definition);
                            else {
                                // trickier conversion
                            }
                        }
                    }
                } else {
                    # Normal Maple Function (currently converts ~35% of all Maple Vars)
                    if(strpos($mapleFunctions[2][$i], '[') === FALSE && strpos($mapleFunctions[2][$i], '{') === FALSE && strpos($mapleFunctions[2][$i], '.') === FALSE && !preg_match('/\w+\(/', $mapleFunctions[2][$i])) {
                        # Means simple 1-to-1 conversion possible
                        $definition = str_replace($mapleFunctions[1][$i], 'sw_maxima_native("' . $mapleFunctions[2][$i] . '")', $definition);
                    } else {
                        // trickier conversion (probably needs a new PhpParser Traverser)
                    }
                }
            }
        }

        return $definition;
    }
}