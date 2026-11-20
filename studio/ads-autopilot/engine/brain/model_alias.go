//go:build kaa_model

// INTEGRATION FILE. Built only with -tags kaa_model (or once
// model_contract.go is deleted and this tag line removed). It makes every
// contract name an alias of LANE ENGINE's engine/model type, so the brain's
// code compiles unchanged. Replace "kaa/model" with the engine module's real
// import path (e.g. "<module>/model").
package brain

import "kaa/model"

type (
	Limits          = model.Limits
	Account         = model.Account
	Campaign        = model.Campaign
	AdSet           = model.AdSet
	Ad              = model.Ad
	MetricRow       = model.MetricRow
	ShopSnapshot    = model.ShopSnapshot
	Product         = model.Product
	OrderDay        = model.OrderDay
	Creative        = model.Creative
	Research        = model.Research
	ResearchNote    = model.ResearchNote
	Source          = model.Source
	ResearchInput   = model.ResearchInput
	LogEntry        = model.LogEntry
	CycleInput      = model.CycleInput
	CycleOutput     = model.CycleOutput
	Proposal        = model.Proposal
	Plan            = model.Plan
	PlanTest        = model.PlanTest
	Allocation      = model.Allocation
	SeasonPush      = model.SeasonPush
	Verdict         = model.Verdict
	Usage           = model.Usage
	DesignSpec      = model.DesignSpec
	CopySet         = model.CopySet
	Economics       = model.Economics
	LintResult      = model.LintResult
	LintIssue       = model.LintIssue
	VideoAsset      = model.VideoAsset
	VideoResults    = model.VideoResults
	VideoAnalysis   = model.VideoAnalysis
	VideoMatch      = model.VideoMatch
	VideoIssue      = model.VideoIssue
	VideoTrim       = model.VideoTrim
	LearningProfile = model.LearningProfile
	Baseline        = model.Baseline
	Slice           = model.Slice
)
