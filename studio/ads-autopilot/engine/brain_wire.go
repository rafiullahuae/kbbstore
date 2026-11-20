package main

import (
	"kaa/brainstub"
	"kaa/model"
)

// newBrain is the one line the integrator changes when LANE BRAIN's engine/brain lands:
//
//	return brain.New(d)
//
// Until then the rules-only stand-in runs, so the engine and its checks work on their own.
func newBrain(d model.BrainDeps) model.Brain { return brainstub.New(d) }
