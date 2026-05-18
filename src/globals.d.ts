// SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
// SPDX-License-Identifier: AGPL-3.0-or-later

/* eslint-disable @typescript-eslint/no-explicit-any */
declare const OC: {
	PERMISSION_SHARE: number
}

declare const OCA: {
	Sharing?: {
		ShareTabSections?: {
			registerSection(callback: (el: HTMLElement[], fileInfo: any) => void): void
		}
	}
	Files?: {
		Sidebar?: {
			state?: {
				tabs?: Array<{
					id: string
					update(fileInfo: any): void
				}>
			}
		}
	}
}
