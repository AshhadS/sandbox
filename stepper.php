<?php
namespace Drupal\dialog_o2a\Service;

use DateInterval;
use DateTime;
use Exception;
use Drupal\dialog_o2a\Model\SelfActivationModel;

class SelfStepperService {
    private array $data_components = [];
    private $mlService;
    private $session_id;
    private $steps;

    public function __construct() {
        $this->mlService =  \Drupal::service('dialog_ml.dialogMLService');
        $this->sa_model = new SelfActivationModel();

    }

    public function set_session($session_id) {
      $this->session_id = $session_id;
    }

    public function get_session() {
      return $this->session_id;
    }

    public function set_step_data($steps) {
      $this->steps = $steps;
    }

    public function get_step_data() {
      $steps = $this->steps;
      $session_id = $this->session_id;

      // If empty query db
      if (empty($steps)) {
        $steps = $this->sa_model->getJsonColumn($session_id, 'step_data');
      }

      return $steps;
    }

    public function getDefaultStepData() {
      $stepData = [
        'select-package' => 0,
        'sim-details' => 0,
        'contact-information' => 0,
        'select-phone-number' => 0,
        'verify-id' => 0,
        'verify-address' => 0,
        'verify-yourself' => 0,
        'confirm-and-pay' => 0,
      ];

      return $stepData;
    }

    public function generateFlowUrl($step, $session_id) {
      $base_url = '/sim';
      $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();


      $step_data = $this->sa_model->getJsonColumn($session_id, 'step_data');
      $this->set_session($session_id);
      $this->set_step_data($step_data);

      $isConfirmVisted = $this->confirmVisted($session_id);
      $isIdVerify = $step == "verify-address";
      $isConfirmation = $step == "confirmation";

      $redirectConfirm = $isConfirmVisted && !$isIdVerify && !$isConfirmation;

      // redirect to confirm if user has visited confirm and not in id verify
      if($redirectConfirm) {
        $step = 'confirm-and-pay';
      }

      $pieces = [$base_url, $step, $session_id];
      $page_url = implode('/', $pieces);

      if($lang != 'en') {
        $page_url .= '?language='.$lang;      
      }

      return $page_url;
    }

    public function isStepEditable($stepName) {
      $editableSteps = ['sim-details', 'contact-information', 'verify-id', 'confirm-and-pay' ];

      return in_array($stepName, $editableSteps);
    }

    // Returns true if user has visted the confirm page - ie completed selfie capture
    public function confirmVisted($session_id) {
      return $this->hasCompletedStep($session_id, 'verify-yourself');
    }

    // Returns true if user has completed the flow
    public function hasCompletedFlow($session_id) {
      return $this->hasCompletedStep($session_id, 'confirm-and-pay');
    }

    // Returns true if user has completed passed step
    public function hasCompletedStep($session_id, $step) {
      // load db step data
      $step_data = $this->get_step_data();
      return $step_data[$step] != 0; 
    }

    public function isManualSimFlow($session_id) {
      $isMaual = true;

      $more_data = $this->sa_model->getJsonColumn($session_id, 'more_data');
      if(isset($more_data['simSerialIsManual']))
        $isMaual =  !!$more_data['simSerialIsManual'];
      
      return $isMaual;
    }

    public function getLastIncompleteStep($session_id)   {
      $step_data = $this->get_step_data();

      $last_incompleted_index = array_search(0, $step_data);
      return $last_incompleted_index;
    }

    // Mark timestamp when step completed
    public function updateStep($session_id, $completed_step, $val = 1) {

      // Defualt Data
      $step_data = $this->getDefaultStepData();

      $new_data = [];
      //check if step name is correct
      if(in_array($completed_step, array_keys($step_data)))
        $new_data[$completed_step] = $val;

      return $this->sa_model->updateJsonColumn($session_id, 'step_data', $new_data, $step_data);
    }

    public function editFlowCheck($session_id, $step) {

      // Init
      $steps = $this->sa_model->getJsonColumn($session_id, 'step_data');
      $this->set_step_data($steps);
      $this->set_session($session_id);
      
      $log = "";
      // Check if user has completed the flow
      if ($this->hasCompletedFlow($session_id)) {
        // Redirect to completion page (success/fail) and exit
        $log .= "hasCompletedFlow:true;";

        if($step == 'confirmation') {
          $log .= "stepIsConfirmation:true;";
          return "SHOW";

        } else {
          $log .= "stepIsConfirmation:false;";
          return $this->generateFlowUrl('confirmation', $session_id);

        }
      }

      // Check if page is editable
      if($this->isStepEditable($step)) {
        // Editable - True
        $log .= "isStepEditable:true;";

        // check if user has updated the nic
        // force user to id verify step
        $isIdUpdated = $steps['verify-address'] == 0;

        // Check has user visited confirm
        if($this->confirmVisted($session_id) && !$isIdUpdated) {
          // Visited Confirm - True
          $log .= "confirmVisted:true;";

          // Check if page user is trying to view is sim details
          if($step == "sim-details") {
            // Is Sim Details - True
            $log .= "sim-details:true;";

            // Check if user selected manual entering flow
            if($this->isManualSimFlow($session_id)) {
              // Is Manual - True
              $log .= "isManualSimFlow:true;";

              // - SHOW PAGE
              $return = "SHOW";

            } else {
              // Is Manual - False
              $log .= "isManualSimFlow:false;";

              // - SHOW CONFIRM
              $return = $this->generateFlowUrl('confirmation', $session_id);

            }

          } else {
            // Is Sim Details - False
            $log .= "sim-details:false;";

            // - SHOW PAGE
            $return = "SHOW";
          }

        } else {
          // Visited Confirm - False
          $log .= "confirmVisted:false;";

          // - SHOW LAST EDITED STEP
          $incomplete_step = $this->getLastIncompleteStep($session_id);
          if($incomplete_step == $step) {
            $log .= "stepMatch:true;";
            $return = "SHOW";

          } else {
            $log .= "stepMatch:false incomplete_step: {$incomplete_step} step: {$step};";
            $return = $this->generateFlowUrl($incomplete_step, $session_id);

          }
        }

      } else {
        // Editable - False
        $log .= "isStepEditable:false;";

        // check if user has updated the nic
        // force user to id verify step
        $isIdUpdated = $steps['verify-address'] == 0;

        // Has user visted confirm
        if($this->confirmVisted($session_id) && !$isIdUpdated) {
          // Visited Confirm - True
          $log .= "confirmVisted:true;";

          // - SHOW CONFIRM
          $return = $this->generateFlowUrl('confirm-and-pay', $session_id);

        } else {
          // Visited Confirm - False
          $log .= "confirmVisted:false;";

          // - SHOW LAST EDITED STEP
          $incomplete_step = $this->getLastIncompleteStep($session_id);
          if($incomplete_step == $step) {
            $log .= "stepMatch:true;";
            $return = "SHOW";

          } else {
            $log .= "stepMatch:false incomplete_step: {$incomplete_step} step: {$step};";
            $return = $this->generateFlowUrl($incomplete_step, $session_id);
          }
        }
      }

      // Prevent redirection loop if same step
      if(strpos($return, $step) !== false) {
        $log .= "same_step_redirect;";
        $return = "SHOW";
      }

      if($return != "SHOW") {
        $log = explode(";", $log);
        \Drupal::logger('StepperValidation')->notice('<pre>' . print_r([$session_id, [
          'step'=> $step,
          'steps' => $steps,
          'log' => $log,
          'return' => $return,
        ]], true) . '</pre>');
      }

      return $return;
    }

    // Check if the user us trying to update and prevent
    public function updatedCheckPrevent($session_id, $step) {
      $step_data = $this->sa_model->getJsonColumn($session_id, 'step_data');

      // Check if step has already been updated
      if($step_data[$step] == '1') {
        $resp['redirect'] = $this->generateFlowUrl('confirm-and-pay', $session_id);
        return $resp;
      }

      return "CREATE";
    }
} 
