<?php

/**
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU GeneralPublic License 3
 */

namespace OpenEMR\Services\Qrda;

use OpenEMR\Services\Qdm\CqmCalculator;
use OpenEMR\Services\Qdm\IndividualResult;
use OpenEMR\Services\Qdm\Interfaces\QdmRequestInterface;
use OpenEMR\Services\Qdm\Measure;
use OpenEMR\Services\Qdm\MeasureService;
use OpenEMR\Services\Qdm\QdmBuilder;
use OpenEMR\Services\Qdm\ResultsCalculator;

class ExportCat3Service
{
    protected $builder;
    protected $calculator;
    protected $request;
    protected $measures = [];
    protected $results = [];

    /**
     * ExportCat3Service constructor.
     *
     * @param CqmCalculator       $calculator
     * @param QdmRequestInterface $request
     */
    public function __construct(QdmBuilder $builder, CqmCalculator $calculator, QdmRequestInterface $request)
    {
        $this->builder = $builder;
        $this->calculator = $calculator;
        $this->request = $request;
    }

    public function export($measures, $effectiveDate, $effectiveDateEnd)
    {
        // let's build our measures from our json
        $measureObjs = [];
        foreach ($measures as $measurePath) {
            $measure_arr = MeasureService::fetchMeasureJson($measurePath);
            $measure = new Measure($measure_arr);
            $measure->measure_path = $measurePath;
            $measureObjs[] = $measure;
        }
        // note that much of this function is following the logic in the cypress test suite
        // @see projectcypress/cypress.git lib/cypress/api_measure_evaluator.rb
        $patients = $this->builder->build($this->request);
        $calculationResults = $this->do_calculation($patients, $measureObjs, $effectiveDate, $effectiveDateEnd);

        // TODO need to get correlation ID from calculator? Maybe bundleId
        $correlation_id = ''; // not sure we need the correlation id at all

        // now we have a hashmap of measure ids(hqmf_id) => IndividualResult[]
        // ResultsCalculator is going to take all of those results and turn them into aggregated population results
        $resultCalculator = new ResultsCalculator($patients, $correlation_id, $effectiveDate);
        $results = $resultCalculator->aggregate_results_for_measures($measureObjs, $calculationResults);

        $options = [
            'start_time' => $effectiveDate,
            'end_time' => $effectiveDateEnd
            /*
             * These are options: TODO what is required?
            $options['provider'];
            $options['start_time'];
            $options['end_time'];
            $options['submission_program'];
            $options['ry2022_submission'];
            */
        ];

        // uses the measures and aggregated result objects (it will do some additional formatting on those objects
        // inside the view.  We could skip some of the double formatting to consolidate all of this but until we've
        // verified we match cypress validation we will try to match the ruby code flow as much as we possibly can.
        $cat3 = new Cat3($results, $measureObjs, $options);
        $string = $cat3->renderXml();

        return $string;
    }


    private function do_calculation($patients, $measures, $effectiveDate, $effectiveEndDate)
    {
        return $this->CqmExecutionCalcExecute($patients, $measures, $effectiveDate, $effectiveEndDate);
        /**
         * measures = product_test.measures
        calc_job = Cypress::CqmExecutionCalc.new(patients.map(&:qdmPatient), measures, correlation_id,
        effectiveDate: Time.at(product_test.measure_period_start).in_time_zone.to_formatted_s(:number))
        calc_job.execute
         */
    }

    private function CqmExecutionCalcExecute($patients, $measures, $effectiveDate, $effectiveEndDate)
    {
        $finalResults = [];
        foreach ($measures as $measure) {
            $results = $this->request_for($patients, $measure, $effectiveDate, $effectiveEndDate);
            // we deviate from the ruby code so we can group these by measure id since we aren't using a database
            $finalResults[$measure->hqmf_id] = $results;
        }
        return $finalResults;
        /**
        def initialize(patients, measures, correlation_id, options)
         *
        @patients            = patients
        # This is a key -> value pair of patients mapped in the form "qdm-patient-id" => BSON::ObjectId("cqm-patient-id")
        @cqm_patient_mapping = patients.map { |patient| [patient.id.to_s, patient.cqmPatient] }.to_h
        @measures            = measures
        @correlation_id      = correlation_id
        @options             = options
        end

        def execute(save: true)
        @measures.map        do |measure|
        request_for(measure, save: save)
        end.flatten
        end
         */
    }

    private function request_for($patients, Measure $measure, $effectiveDate, $effectiveDateEnd)
    {

        $results = $this->calculator->calculateMeasure($patients, $measure->measure_path, $effectiveDate, $effectiveDateEnd);
        $final_results = [];
        foreach ($results as $patient_id => $result) {
            // we will deviate here as we don't need the patient as we aren't saving any data for cypress with the patient
            $aggregated_results = $this->aggregate_population_results_from_individual_results($result, $patient_id);
            $final_results = array_merge($final_results, $aggregated_results);
        }

        // note we aren't saving data to the database and so we are foregoing the hash return here.
        // Cypress runs a query on all IndividualResults conected to the measure_id && correlation_id which we are
        // going to skip over and just return an array of all of the results from our aggregation
        // which ends up being our individual results list per measure.
        return $final_results;

        /**
        def request_for(measure, save: true)
        ir_list = []
         *
        @options['requestDocument'] = true
        post_data = { patients: @patients, measure: measure, valueSets: measure.value_sets, options: @options }
        # cqm-execution-service expects a field called value_set_oids which is really just our
        # oids field. There is a value_set_oids on the measure for this explicit purpose.
        post_data = post_data.to_json(methods: %i[_type])
        begin
        response = RestClient::Request.execute(method: :post, url: self.class.create_connection_string, timeout: 120,
        payload: post_data, headers: { content_type: 'application/json' })
        rescue StandardError => e
        raise e.to_s || 'Calculation failed without an error message'
        end
        results = JSON.parse(response)

        patient_result_hash = {}
        results.each do |patient_id, result|
        # Aggregate the results returned from the calculation engine for a specific patient.
        # If saving the individual results, update identifiers (patient id, population_set_key) in the individual result.
        aggregate_population_results_from_individual_results(result, @cqm_patient_mapping[patient_id], save, ir_list)
        patient_result_hash[patient_id] = result.values
        end
        measure.calculation_results.create(ir_list) if save
        patient_result_hash.values
        end
         */
    }

    private function aggregate_population_results_from_individual_results($individual_results, $patient_id)
    {

        $results = [];
        foreach ($individual_results as $population_set_key => $individual_result) {
            $individual_result['population_set_key'] = $population_set_key;
            $individual_result['patient_id'] = $patient_id;
            $results[] = new IndividualResult($individual_result);
        }
        return $results;
        /**
        def aggregate_population_results_from_individual_results(individual_results, patient, save, ir_list)
        individual_results.each_pair do |population_set_key, individual_result|
        # store the population_set within the indivdual result
        individual_result['population_set_key'] = population_set_key
        # update the patient_id to match the cqm_patient id, not the qdm_patient id
        individual_result['patient_id'] = patient.id.to_s
        # save to database (if in the IPP)
        ir_list << postprocess_individual_result(individual_result) if save && individual_result.IPP != 0
        # update the patients, measure_relevance_hash
        patient.update_measure_relevance_hash(individual_result) if individual_result.IPP != 0
        end
        patient.save if save
        end
         */
    }
}
