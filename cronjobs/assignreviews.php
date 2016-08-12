<?php
require_once("peerreview/inc/common.php");

class AssignReviewsPeerReviewCronJob
{
    function executeAndGetResult(AssignmentID $assignmentID, PDODataManager $globalDataMgr)
    {
    	try{
	    	//First check if the job has already been done
			if($globalDataMgr->isJobDone($assignmentID, 'assignreviews'))
				return;
			
			$configuration = $globalDataMgr->getCourseConfiguration($assignmentID);
				
	        $currentAssignment = $globalDataMgr->getAssignment($assignmentID);
			
	        $windowSize = $configuration->windowSize;//$windowSize = require_from_post("windowsize");
	        if($configuration->numReviews < 0)
			{
				$this->numReviews = $currentAssignment->defaultNumberOfReviews;
			}
			else
			{
	        	$this->numReviews = $configuration->numReviews;//$this->numReviews = require_from_post("numreviews");
			}
	        $this->scoreNoise = $configuration->scoreNoise;//$this->scoreNoise = require_from_post("scorenoise");
	        $this->maxAttempts = $configuration->maxAttempts;//$this->maxAttempts = require_from_post("maxattempts");
	        $this->seed = $currentAssignment->submissionStartDate;//$this->seed = require_from_post("seed");
	        $this->numCovertCalibrations = $configuration->numCovertCalibrations;//$this->numCovertCalibrations = require_from_post("numCovertCalibrations");
	        $this->exhaustedCondition = $configuration->exhaustedCondition;//set in course configuration
	        $this->scoreMap = array();
	
	        $assignments = $currentAssignment->getAssignmentsBefore($windowSize);
	        $userNameMap = $globalDataMgr->getUserDisplayMapByAssignment($assignmentID);
			$authors = $currentAssignment->getAuthorSubmissionMap();
	        $activeAuthors = $currentAssignment->getActiveAuthorSubmissionMap_();
	        $assignmentIndependent = $currentAssignment->getIndependentUsers();
	        	
			if(isset($this->numCoverCalibrations) && $this->numCoverCalibrations > sizeof($currentAssignment->getCalibrationSubmissionIDs()))//Check that there are at least as many calibration submissions as covert reviews to be assigned
				throw new Exception("There are more covert calibrations requested for each independent student than there are available calibration submissions");
	
	        //First delete old covert calibration reviews
			foreach($currentAssignment->getStudentToCovertReviewsMap() as $student => $covertReviews)
			{
				foreach($covertReviews as $matchID)
				{
					$currentAssignment->removeMatch(new MatchID($matchID));
				}
			}
			
	        $independents = array();
	        $supervised = array();
	        foreach($activeAuthors as $author => $essayID)
	        {
	            $score = compute_peer_review_score_for_assignments(new UserID($author), $assignments);
	
	            if(array_key_exists($author, $assignmentIndependent))
	                $independents[$author] = $score;
	            else
	                $supervised[$author] = $score;
	            $this->scoreMap[$author] = $score;
	        }
	
	        $html = "";
			$html .= "Score noise used: ".$this->scoreNoise."<br>";
	        $html .= "Max attempts used: ".$this->maxAttempts."<br>";
	        $html .= "Number of covert calibrations assigned: ".$this->numCovertCalibrations."<br>";
	        $html .= "Condition for exhausted condition used: ".$this->exhaustedCondition."<br>";
	        
	        # If the independent pool is too small, we move all of its users into the supervised pool.
	        # If the supervised pool is too small, then we move just enough independent users into the supervised pool.
	        if((count($independents) <= $this->numReviews && count($independents) > 0) ||
	           (count($supervised) <= ($this->numReviews + $this->numCovertCalibrations) && count($supervised) > 0))
	        {
	          $numIndep = count($independents);
	          $keys = array_keys($independents);
	          mt_shuffle($keys);
	
	          foreach($keys as $idx => $author)
	          {
	            $supervised[$author] = $independents[$author];
	            unset($independents[$author]);
	
	            if((count($independents) == 0 || count($independents) > $this->numReviews) &&
	               (count($supervised) == 0 || count($supervised) > ($this->numReviews + $this->numCovertCalibrations))) {
	              break;
	            }
	          }
	          $html .= "<p><b style='color:red'>Warning: Topped up supervised pool with ".($numIndep-count($independents))." independent students.</b>";
	        }
			
			if(count($supervised) <= ($this->numReviews + $this->numCovertCalibrations))
				throw new Exception("There is not enough students for the number of reviews requested");
			
	        $independentAssignment = $this->getReviewAssignment($independents, $this->numReviews);
	        $supervisedAssignment = $this->getReviewAssignment($supervised, $this->numReviews + $this->numCovertCalibrations);
			
			//For reporting how many independents got x covert reviews
			$covertReviewsHistogram = array();
			//For reporting how many independents got x extra peer reviews
			$extraPeerReviewsHistogram = array();
			
			$covertAssignment = array();
			foreach($independents as $independent => $_)
			{
				$j = 0;
				$cr = 0;
				$epr = 0;
				while($j < $this->numCovertCalibrations)
				{
					$newSubmissionID = $currentAssignment->getNewCalibrationSubmissionForUser(new UserID($independent));
					if($newSubmissionID)
					{
						$submission = $currentAssignment->getSubmission($newSubmissionID);
						$authorID = $submission->authorID;
						if(!array_key_exists($authorID->id, $covertAssignment))
							$covertAssignment[$authorID->id] = array();
						$covertAssignment[$authorID->id][] = $independent;
						$cr++;
					}
					else
					{
						if($this->exhaustedCondition == 'extrapeerreview')
						{
							//TODO: Fix this algorithm. Doesn't work for case where the candidate(s) with the fewest reviewers are already reviewed by the current independent. Although unlikely
							$reviewersForEach = array_map(function($item){ return sizeof($item); }, $independentAssignment);
							$minimum_reviewers = min($reviewersForEach);
							$candidates = array_filter($independentAssignment, function($x) use ($minimum_reviewers){return (sizeof($x) == $minimum_reviewers); });
							$candidates = array_keys($candidates);
							shuffle($candidates);
							foreach($candidates as $candidate)
							{
								if(!in_array($independent, $independentAssignment[$candidate]) && $candidate != $independent)
								{
									$independentAssignment[$candidate][] = $independent;
									$epr++;
									break;
								}
							}
						}
						else
							throw new Exception("Some independent student(s) has exhausted all calibration reviews and thus cannot be assigned a covert peer review.");
					}
					$j++;
				}
				$covertReviewsHistogram[$cr]++;
				$extraPeerReviewsHistogram[$epr]++;
			}
			
	       	//Build the HTML for this
	        $html .= "<h2>Independent</h2>\n";
	        $html .= $this->getTableForAssignment($independentAssignment, $independents, $userNameMap);
	        $html .= "<h2>Supervised</h2>\n";
	        $html .= $this->getTableForAssignment($supervisedAssignment, $supervised, $userNameMap);

	        foreach($covertAssignment as $author => $reviewers)
	            $reviewerAssignment[$authors[$author]->id] = $reviewers;
	        foreach($independentAssignment as $author => $reviewers)
	            $reviewerAssignment[$authors[$author]->id] = $reviewers;	
	        foreach($supervisedAssignment as $author => $reviewers)
	            $reviewerAssignment[$authors[$author]->id] = $reviewers;

	        $currentAssignment->saveReviewerAssignment($reviewerAssignment);

			if($this->numCovertCalibrations > 0 && sizeof($independents) > 0)
			{
	        	$studentToCovertReviewsMap = $currentAssignment->getStudentToCovertReviewsMap();
				
		        $html .= "<h2>Covert Reviews</h2>";
				$html .= "<table>";
				foreach($studentToCovertReviewsMap as $reviewer => $covertReviews)
				{
		        	$html .= "<tr><td>".$userNameMap[$reviewer]."</td><td><ul style='list-style-type: none;'>";
					foreach($covertReviews as $covertMatch)
					{
						$submission = $currentAssignment->getSubmission(new MatchID ($covertMatch));
						$html .= "<li>".$userNameMap[$submission->authorID->id]."</li>";
					}
					$html .= "</ul></td></tr>";
				}
				$html .= "</table>";
			}
			
			//For summary
			$summary = "";
			if(sizeof($independents)>0)
			{
				$summary .= "For ".sizeof($independents)." in the independents group: ".sizeof($independents)." have ".$this->numReviews." peer reviews, ";
				if($this->numCovertCalibrations > 0 && sizeof($independents) > 0)
				{	
					$k = 0;
					while($k <= $this->numCovertCalibrations)
					{
						if($covertReviewsHistogram[$k] > 0)
						{
							$summary .= $covertReviewsHistogram[$k] . " have $k covert reviews, ";
						}
						$k++;
					}
					$k = 0;
					while($k <= $this->numCovertCalibrations)
					{
						if($extraPeerReviewsHistogram[$k] > 0)
							$summary .= $extraPeerReviewsHistogram[$k] . " have $k extra peer reviews, ";
						$k++;
					}
				}
			}
			if(sizeof($supervised)>0)
				$summary .= "<br>For " . sizeof($supervised) . " in the supervised group: " . sizeof($supervised) . " have " . ($this->numReviews + $this->numCovertCalibrations) . " peer reviews";
			//End of summary

			$globalDataMgr->createNotification($assignmentID, 'assignreviews', 1, $summary, $html);
		}catch(Exception $exception){
			$globalDataMgr->createNotification($assignmentID, 'assignreviews', 0, cleanString($exception->getMessage()), "");
		}
    }


    private function getTableForAssignment($assignment, $scoreMap, $nameMap)
    {
        $html = "<table width='100%'>\n";
        foreach($assignment as $author => $reviewers)
        {
            $html .= "<tr><td>".$nameMap[$author]." (".precisionFloat($this->getReviewerScores($reviewers) *1.0/ sizeof($reviewers)).")</td>";
            foreach($reviewers as $reviewer)
            {
                $html .= "<td>".$nameMap[$reviewer]." (".precisionFloat($scoreMap[$reviewer]).")</td>";
            }
            $html .= "</tr>\n";
        }

        $html .= "</table>\n";
        return $html;
    }

    private function getReviewAssignment($students, $numReviews)
    {
        mt_srand($this->seed);
        #print_r($students);
        for($i = 0; $i < $this->maxAttempts; $i++)
        {
            try {
                $res = $this->_getReviewAssignment($students, $numReviews);
                return $res;
            }catch(Exception $e){
                //They didn't get it
            }
        }
        throw new Exception("Could not get a reviewer assignment - try increasing the number of attempts or the score noise. If that fails, play with your seeds and hope for the best.");
    }

    private function _getReviewAssignment($students, $numReviews)
    {
        //First, we need to build up our array of student/scores, such that we get a total ordering
        $reviewers = array();
        $randMax = mt_getrandmax();
		
        foreach($students as $student => $score)
        {
            $obj = new stdClass;
            $obj->student = $student;
            $noise = (mt_rand()*1.0/$randMax * 2 - 1)*$this->scoreNoise;
            $obj->score = max(0, min(1, ($score + $noise)));
            $reviewers[] = $obj;
        }
        //Now, we need to sort these guys, so that good reviewers are at the top
        usort($reviewers, function($a, $b) { if( abs($a->score - $b->score) < 0.00001) { return $a->student < $b->student; } else { return $a->score < $b->score; } } );

		$neworder = array();
		$vanderCorput = array(); $base = 2; $i = 0; $j = 0; $limit = 1; $k = 0;
		for($a = 0; $a < sizeof($reviewers) + 1; $a++)
		{
			$vanderCorput[] = $i * pow($base, $k) + $j * pow($base, $k-1); 
			$i++;
			if($i == $limit){ $i = 0; $j++;} 
			if($j == $base){ $i = 0; $limit *= $base; $j = 1; $k--;}
		}
		$i = 1;
		foreach($reviewers as $index => $obj)
		{
			$obj->var = $vanderCorput[$i];  
			$neworder[] = $obj;
			$i++;
		}

		usort($neworder, function($a, $b) { return $a->var < $b->var; } );
		
		$output = "";
        $assignment = array();
		
		$numStudents = sizeof($neworder);
		foreach($neworder as $rank => $obj)
		{
			$output .= $obj->student." [ ";
			$assignment[$obj->student] = array();
			for($i = $rank+1; $i < $rank+1+$numReviews; $i++)
			{
				$output .= $neworder[$i%$numStudents]->student.", ";
				$assignment[$obj->student][] = $neworder[$i%$numStudents]->student;
			}
			$output .= "]";
		}
		
		foreach($assignment as $authorID => $array)
		{
			$hash = array();
			foreach($array as $element)
			{
				if(isset($hash[$element]))
					throw new Exception("Reviewer assigned to one submission more than once");
				if($element == $authorID)
					throw new Exception("Reviewer assigned to his own submission");
				$hash[$element] = 1;
			}
		}
			
        return $assignment;
    }

    private function compareReviewerScores($a, $b)
    {
        return $this->getReviewerScores($a) > $this->getReviewerScores($b);
    }

    private function getReviewerScores($array)
    {
        $score = 0;
        foreach($array as $reviewer)
        {
            $score += $this->scoreMap[$reviewer];
        }
        return $score;
    }

    //Not used anymore
    /*private function popNextReviewer($author, $assigned, &$reviewers)
    {
        foreach($reviewers as $index => $obj)
        {
            if($obj->student != $author && !in_array($obj->student, $assigned))
            {
                unset($reviewers[$index]);
                return $obj->student;
            }
        }
        throw new Exception("Failed to find a valid author");
    }*/
}
