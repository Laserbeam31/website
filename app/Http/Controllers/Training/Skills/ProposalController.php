<?php

namespace App\Http\Controllers\Training\Skills;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\Skills\SubmitProposal;
use App\Mail\Training\Skills\ProposalProcessed;
use App\Mail\Training\Skills\ProposalSubmitted;
use App\Models\Training\Skills\Proposal;
use App\Models\Training\Skills\Skill;
use App\Models\Users\User;
use bnjns\LaravelNotifications\Facades\Notify;
use bnjns\WebDevTools\Laravel\Traits\ChecksPaginationPage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Validator;

class ProposalController extends Controller
{
    use ChecksPaginationPage;

    /**
     * ProposalController constructor.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * View the form to propose a new skill level.
     *
     * @param int|null $id
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function form($id = null)
    {
        $this->authorize('propose', Proposal::class);

        $levels = $this->determineSelectableProposalSkillLevels($skill = $id === null ? null : Skill::find($id), $user = request()->user());

        if (count(array_filter($levels)) == 0) {
            Notify::warning('There are no levels left to apply for');
            return redirect()->route('training.skill.index');
        }

        if ($skill && $user->getSkillLevel($skill) === 3) {
            Notify::warning('You are already ' . Skill::LEVEL_NAMES[3] . ' for this skill');
            return redirect()->route('training.skill.view', ['id' => $skill->id]);
        }

        if ($skill && $user->hasProposalPending($skill)) {
            Notify::warning('You already have a proposal pending for this skill');
            return redirect()->route('training.skill.view', ['id' => $skill->id]);
        }

        return view('training.skills.proposals.propose')->with([
            'skill'           => $id === null ? null : $skill,
            'AvailableLevels' => array_keys(array_filter($levels)),
        ]);
    }

    /**
     * Process the form and submit the proposal.
     *
     * @param \App\Http\Requests\Training\Skills\SubmitProposal $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function propose(SubmitProposal $request)
    {
        // Create the proposal
        $skill    = Skill::find($request->get('skill_id'));
        $user     = $request->user();
        $proposal = Proposal::create([
            'skill_id'        => $skill->id,
            'user_id'         => $user->id,
            'proposed_level'  => clean($request->get('level')),
            'reasoning'       => clean($request->get('reasoning')),
            'date'            => Carbon::now(),
            'awarded_level'   => null,
            'awarded_by'      => null,
            'awarded_comment' => null,
            'awarded_date'    => null,
        ]);

        // Email the T&S officer
        Mail::to('training@bts-crew.com')
            ->queue(new ProposalSubmitted($skill, $proposal, $user));

        Notify::success('Proposal submitted');
        return redirect()->route('training.skill.index');
    }

    /**
     * View the proposal index page.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $unawarded = Proposal::notAwarded()->orderBy('date', 'ASC')->get();
        $awarded   = Proposal::awarded()->orderBy('awarded_date', 'DESC')->paginate(30);
        $this->checkPage($awarded);

        return view('training.skills.proposals.index')->with([
            'unawarded' => $unawarded,
            'awarded'   => $awarded,
            'tab'       => request()->get('tab') == 'reviewed' ? 'reviewed' : 'pending',
        ]);
    }

    /**
     * View the details of a proposal.
     *
     * @param $id
     *
     * @return \Illuminate\View\View
     */
    public function view($id)
    {
        $proposal = Proposal::findOrFail($id);
        $this->authorize('view', $proposal);

        $levels = [
            0 => 'Do not award',
        ];
        for ($i = 1; $i <= 3; $i++) {
            if ($proposal->skill->isLevelAvailable($i)) {
                $levels[$i] = Skill::LEVEL_NAMES[$i];
            }
        }

        return view('training.skills.proposals.view')->with([
            'proposal' => $proposal,
            'levels'   => $levels,
        ]);
    }

    /**
     * Process the proposal form and update the details.
     *
     * @param                          $id
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update($id, Request $request)
    {
        $proposal = Proposal::findOrFail($id);
        $this->authorize('update', $proposal);

        // If the proposal is already awarded, go back to viewing
        if ($proposal->isAwarded()) {
            return redirect()->route('training.skill.proposal.view', ['id' => $id]);
        }

        // Check the user isn't trying to review their own proposal
        $user = request()->user();
        if ($proposal->user_id == $user->id) {
            Notify::warning('You can\'t review your own proposal');
            return redirect()->route('training.skill.proposal.index');
        }

        // Validate the request
        $this->validateWith(validator($request->all(), [
            'awarded_level'   => 'required|in:0,1,2,3',
            'awarded_comment' => 'required_if:awarded_level,0',
        ], [
            'awarded_level.required'      => 'Please select the level to award',
            'awarded_level.in'            => 'Please select the level to award',
            'awarded_comment.required_if' => 'Please provide a reason why you haven\'t awarded the requested level',
        ])->after(function (Validator $validator) use ($request) {
            $skill = Skill::find($request->get('skill_id'));

            // Check the level requested is available
            if (!$skill->isLevelAvailable($this->get('level'))) {
                $validator->errors()->add('awarded_level', 'Please select a level that\'s available');
            }
        }));

        // Update the proposal
        $proposal->update([
            'awarded_level'   => $request->get('awarded_level'),
            'awarded_by'      => $user->id,
            'awarded_comment' => clean($request->get('awarded_comment')),
            'awarded_date'    => Carbon::now(),
        ]);

        // Update the user's skill level
        if ($request->get('awarded_level') > 0) {
            $proposal->user->setSkillLevel($proposal->skill_id, $request->get('awarded_level'));
        }

        // Email the user
        Mail::to($proposal->user->email, $proposal->user->name)
            ->queue(new ProposalProcessed($proposal));

        Notify::success('Proposal processed');
        return redirect()->route('training.skill.proposal.index');
    }

    /**
     * This method determines the skill levels that can be selected when submitting a proposal.
     *
     * @param \App\Models\Training\Skills\Skill|null $skill
     * @param \App\Models\Users\User                 $user
     *
     * @return array
     */
    private function determineSelectableProposalSkillLevels(Skill $skill = null, User $user)
    {
        $levels = [];
        for ($i = 1; $i <= 3; $i++) {
            $levels[$i] = $skill == null ? true : $skill->isLevelAvailable($i);
        }

        if ($skill && $user->hasSkill($skill)) {
            for ($i = 1; $i <= $user->getSkillLevel($skill); $i++) {
                $levels[$i] = false;
            }
        }

        return $levels;
    }
}
