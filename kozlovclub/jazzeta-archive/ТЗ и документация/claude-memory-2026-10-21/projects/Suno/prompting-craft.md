---
name: "prompting-craft"
description: "Suno prompt syntax rules, iteration discipline, generation workflow, and hard-won learnings about what degrades vocals"
---

## Syntax and structure

- Standard prompt structure is the four-part format from the pimenov.ai guide: Genre / Exclude / Instruments / Tags
- Round parentheses get sung by Suno; square brackets should be used for delivery instructions — a critical structural discovery from the pimenov.ai Suno guide
- Full orchestral section naming is a key technique for children's symphonic/jazz overture prompts
- African percussion elements must be explicitly preserved; they tend to get diluted or dropped without reinforcement

## Iteration discipline

- Change one element at a time — stacking multiple prompt changes simultaneously causes progressive degradation; Teofunk explicitly flagged this when early prompts outperformed later ones
- Conflicting descriptors degrade vocals — stacking vocal identities (e.g. simultaneous rap flow plus sung hook) or clashing genre tags (e.g. "crisp bold production" against organic warmth) produces synthetic-sounding results
- Return to the working base — when prompts drift, revert to the last confirmed working foundation and make surgical additions only
- Base prompt is locked once vocals are working; one variable changed per iteration thereafter

## Generation workflow

- Generate 4–6 variants, select by the first 10–15 seconds, then lock successful vocals as a Suno Persona

## Working with Claude

- Preferred delivery format: complete prompt plus lyrics together in one package; concise and direct
- Claude's role is to deliver optimized Suno prompts on request
- Before complex or technically demanding prompts, search for current Suno prompting techniques to ensure accuracy and up-to-date best practices

## Tools and resources

- Suno AI — primary music generation platform
- pimenov.ai Suno guide — reference for prompt structure and syntax rules
